<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

/**
 * Scans a deployed site's code over SSH to work out which environment
 * variables it expects, so the Environment UI can warn when required ones
 * are missing from the live .env.
 *
 * Three sources are merged, each contributing a `source` tag:
 *   - example: keys (and their sample values) declared in .env.example
 *              (or .env.local.example / .env.dist). The author's contract.
 *   - code:    env('KEY') calls in application PHP with NO default argument —
 *              these have no fallback, so a missing value breaks the app.
 *   - config:  env('KEY', ...) calls anywhere under config/. This is where
 *              Laravel actually consumes env, so it's the richest list of
 *              "what does this app read" — most carry a default and are only
 *              advisory.
 *
 * A key is treated as REQUIRED when it appears in .env.example or as a
 * no-default env() call (in app code or config). Config keys that only ever
 * appear WITH a default are recorded but flagged optional.
 *
 * SSH-only and read-only: runs `cat`/`grep` over the live host (always via
 * sudo, mirroring {@see SiteEnvReader}) and returns a plain array. It never
 * touches the DB — callers (the queued job) persist the result.
 */
class SiteEnvRequirementScanner
{
    public function __construct(private readonly DotEnvFileParser $parser) {}

    /**
     * @return array{
     *     scanned_at: string,
     *     root: string,
     *     example_path: ?string,
     *     keys: list<array{key: string, sources: list<string>, required: bool, example: ?string}>
     * }
     */
    public function scan(Site $site): array
    {
        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        // The app root: the active release dir for atomic deploys, otherwise
        // the repository path. This is where .env, config/ and the app code
        // live — the same directory .env is read from / written to.
        $root = rtrim($site->effectiveEnvDirectory(), '/');
        if ($root === '' || ! str_starts_with($root, '/')) {
            throw new \RuntimeException('Site has no resolvable repository path to scan.');
        }

        $output = (new SshConnection($server))->exec($this->buildScript($root), 120);

        $exampleBlock = $this->section($output, 'EXAMPLE');
        $requiredBlock = $this->section($output, 'REQUIRED');
        $configBlock = $this->section($output, 'CONFIG');

        $examplePath = trim($this->section($output, 'EXAMPLE_PATH'));

        // .env.example → key => sample value (and the declared key set).
        $exampleParsed = $this->parser->parse($exampleBlock);
        $exampleValues = $exampleParsed['variables'];

        $requiredKeys = $this->extractKeys($requiredBlock);
        $configKeys = $this->extractKeys($configBlock);
        $exampleKeys = array_keys($exampleValues);

        // Merge into one keyed map so each key collects every source it came
        // from. Order of discovery doesn't matter; we sort at the end.
        $merged = [];
        foreach ($exampleKeys as $key) {
            $merged[$key]['sources']['example'] = true;
        }
        foreach ($requiredKeys as $key) {
            $merged[$key]['sources']['code'] = true;
        }
        foreach ($configKeys as $key) {
            $merged[$key]['sources']['config'] = true;
        }

        $keys = [];
        foreach ($merged as $key => $data) {
            $sources = array_keys($data['sources']);
            // Required when declared in .env.example or referenced without a
            // default (the no-default grep only ever populates 'code').
            $required = in_array('example', $sources, true) || in_array('code', $sources, true);
            $keys[] = [
                'key' => $key,
                'sources' => $sources,
                'required' => $required,
                'example' => $exampleValues[$key] ?? null,
            ];
        }

        usort($keys, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return [
            'scanned_at' => now()->toIso8601String(),
            'root' => $root,
            'example_path' => $examplePath !== '' ? $examplePath : null,
            'keys' => $keys,
        ];
    }

    /**
     * One SSH round-trip that emits four labelled sections. The whole script
     * is base64-encoded and decoded remote-side so we never fight nested
     * shell quoting — the grep patterns contain regex metacharacters that
     * are murder to quote through SSH otherwise.
     *
     * The quote character in env('KEY') / env("KEY") is matched with a bare
     * `.` (any single char) rather than a ['"] class, so the pattern itself
     * carries no quote characters; PHP {@see extractKeys()} pulls the key out
     * precisely afterwards. Each grep is scoped to *.php and skips
     * vendor/storage/node_modules/tests.
     *
     * Runs as the SSH (deploy) user — it owns the code and .env.example, so
     * no sudo is needed; that also keeps login/MOTD noise out of the output.
     */
    private function buildScript(string $root): string
    {
        $r = escapeshellarg($root);

        // `.` stands in for the surrounding quote. No-default = closing paren
        // right after the key → genuinely required.
        $noDefault = 'env\\([[:space:]]*.[A-Z_][A-Z0-9_]*.[[:space:]]*\\)';
        // Any reference (with or without a default) — used to enumerate every
        // var config/ consumes.
        $anyRef = 'env\\([[:space:]]*.[A-Z_][A-Z0-9_]*';

        $excludes = "--include='*.php' --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage --exclude-dir=tests --exclude-dir=.git";

        $inner = implode("\n", [
            'set +e',
            'EX=""; for f in .env.example .env.local.example .env.dist; do if [ -f '.$r.'/"$f" ]; then EX='.$r.'/"$f"; echo "$f"; break; fi; done',
            'echo DPLY_EXAMPLE_PATH_END',
            'echo DPLY_EXAMPLE_BEGIN',
            '[ -n "$EX" ] && cat "$EX"',
            'echo DPLY_EXAMPLE_END',
            'echo DPLY_REQUIRED_BEGIN',
            'grep -rhoE "'.$noDefault.'" '.$excludes.' '.$r.' 2>/dev/null | sort -u',
            'echo DPLY_REQUIRED_END',
            'echo DPLY_CONFIG_BEGIN',
            '[ -d '.$r.'/config ] && grep -rhoE "'.$anyRef.'" '.$excludes.' '.$r.'/config 2>/dev/null | sort -u',
            'echo DPLY_CONFIG_END',
        ]);

        return 'echo '.escapeshellarg(base64_encode($inner)).' | base64 -d | bash';
    }

    /**
     * Pull the text between DPLY_<NAME>_BEGIN/END (or before
     * DPLY_<NAME>_END for the path marker) out of the combined output.
     */
    private function section(string $output, string $name): string
    {
        if ($name === 'EXAMPLE_PATH') {
            // The path is everything before the first DPLY_EXAMPLE_PATH_END.
            $end = strpos($output, 'DPLY_EXAMPLE_PATH_END');

            return $end === false ? '' : trim(substr($output, 0, $end));
        }

        $begin = strpos($output, 'DPLY_'.$name.'_BEGIN');
        $end = strpos($output, 'DPLY_'.$name.'_END');
        if ($begin === false || $end === false || $end < $begin) {
            return '';
        }
        $start = $begin + strlen('DPLY_'.$name.'_BEGIN');

        return trim(substr($output, $start, $end - $start));
    }

    /**
     * Turn grep output lines like `env('APP_KEY')` / `env("DB_HOST"` into a
     * unique list of KEY names.
     *
     * @return list<string>
     */
    private function extractKeys(string $block): array
    {
        if (trim($block) === '') {
            return [];
        }

        $keys = [];
        foreach (preg_split('/\r\n|\r|\n/', $block) ?: [] as $line) {
            // Matches env('KEY' / env("KEY' / env( 'KEY — the single char after
            // env( is the opening quote (the grep used `.` for it).
            if (preg_match('/env\(\s*.([A-Z_][A-Z0-9_]*)/', $line, $m) === 1) {
                $keys[$m[1]] = true;
            }
        }

        return array_keys($keys);
    }
}
