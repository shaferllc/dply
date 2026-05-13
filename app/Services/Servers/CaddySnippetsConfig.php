<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write Caddy snippets in `/etc/caddy/Caddyfile`. Snippets are
 * named, parenthesised blocks that sites import to share config:
 *
 *   (common_headers) {
 *       header X-Frame-Options "DENY"
 *       header X-Content-Type-Options "nosniff"
 *   }
 *
 *   example.com {
 *       import common_headers
 *       reverse_proxy localhost:8080
 *   }
 *
 * Site blocks and the global `{ ... }` options pass through byte-for-byte.
 *
 * Save/add/remove all snapshot → atomic install → `caddy validate` →
 * reload, with rollback from the .dply-bak.<ts> snapshot on validation
 * failure. Mirrors {@see OpenLiteSpeedExtAppsConfig}.
 */
class CaddySnippetsConfig
{
    private const REMOTE_PATH = '/etc/caddy/Caddyfile';

    /**
     * Pull every snippet block. Returns one entry per snippet with the
     * raw body (everything between the opening `(name) {` and the
     * matching close brace).
     *
     * @return array{snippets: list<array{name: string, body: string, raw: string}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['snippets' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['snippets' => [], 'unreadable' => true];
        }

        return ['snippets' => $this->findSnippetBlocks($contents), 'unreadable' => false];
    }

    /**
     * Rewrite the bodies of multiple snippets. `$updates` is keyed by
     * snippet name → new body text. Snippets not in the update map are
     * left untouched.
     *
     * @param  array<string, string>  $updates
     * @throws \RuntimeException
     */
    public function save(Server $server, array $updates, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        if ($updates === []) {
            $emit->warn('No snippet updates supplied.');

            return;
        }

        $emit->step('caddy-snippets', 'Reading current Caddyfile');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read Caddyfile from the server.');
        }

        $newContents = $contents;
        $rewritten = 0;
        foreach ($this->findSnippetBlocks($contents) as $snippet) {
            if (! array_key_exists($snippet['name'], $updates)) {
                continue;
            }
            $newBody = trim((string) $updates[$snippet['name']]);
            $rendered = $this->renderSnippet($snippet['name'], $newBody);
            $newContents = str_replace($snippet['raw'], $rendered, $newContents);
            $rewritten++;
            $emit->info('[caddy-snippets] Rewriting snippet: '.$snippet['name']);
        }

        if ($rewritten === 0) {
            $emit->warn('No matching snippet blocks were rewritten.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'rewrite '.$rewritten.' snippet(s)');
        $emit->success('Caddy reloaded with the updated snippets.');
    }

    /**
     * Append a new `(name) { ... }` snippet block at the end of the file.
     *
     * @throws \RuntimeException
     */
    public function addSnippet(Server $server, string $name, string $body, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $name = trim($name);
        $body = trim($body);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if ($body === '') {
            throw new \RuntimeException('Snippet body cannot be empty.');
        }

        $emit->step('caddy-snippets', 'Reading current Caddyfile');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read Caddyfile from the server.');
        }

        foreach ($this->findSnippetBlocks($contents) as $snippet) {
            if ($snippet['name'] === $name) {
                throw new \RuntimeException("A snippet named `({$name})` already exists. Use a different name.");
            }
        }

        $rendered = $this->renderSnippet($name, $body);
        $newContents = rtrim($contents, "\n")."\n\n".$rendered."\n";

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'add snippet '.$name);
        $emit->success('Snippet ('.$name.') added.');
    }

    /**
     * Strip a snippet block by name. Best-effort warning when other
     * sites still `import` the name — the validate step will still catch
     * a broken reference, but warning ahead helps the operator decide.
     *
     * @throws \RuntimeException
     */
    public function removeSnippet(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('caddy-snippets', 'Reading current Caddyfile');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read Caddyfile from the server.');
        }

        $found = false;
        $newContents = $contents;
        foreach ($this->findSnippetBlocks($contents) as $snippet) {
            if ($snippet['name'] === $name) {
                // Also strip a trailing blank line so the file doesn't accumulate.
                $pattern = '/'.preg_quote($snippet['raw'], '/').'\R?\R?/';
                $newContents = preg_replace($pattern, '', $newContents, 1) ?? $newContents;
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("No snippet named `({$name})` found.");
        }

        if (preg_match('/^\s*import\s+'.preg_quote($name, '/').'\b/m', $newContents) === 1) {
            $emit->warn('Other blocks still `import '.$name.'` — `caddy validate` will fail unless those are updated too.');
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'remove snippet '.$name);
        $emit->success('Snippet ('.$name.') removed.');
    }

    /**
     * Render a snippet block from its name + body. Body lines that are
     * not already indented get a leading tab so the file stays
     * canonical-ish (and `caddy fmt` is a no-op).
     */
    private function renderSnippet(string $name, string $body): string
    {
        $bodyLines = preg_split('/\R/', $body) ?: [];
        $rendered = ['('.$name.') {'];
        foreach ($bodyLines as $line) {
            $stripped = rtrim($line);
            if ($stripped === '') {
                $rendered[] = '';

                continue;
            }
            // If already indented (operator-supplied), keep as-is; otherwise
            // prepend a tab so the snippet body sits one level inside the
            // parens — matches `caddy fmt`'s default style.
            $rendered[] = (strncmp($stripped, "\t", 1) === 0 || strncmp($stripped, ' ', 1) === 0)
                ? $stripped
                : "\t".$stripped;
        }
        $rendered[] = '}';

        return implode("\n", $rendered);
    }

    /**
     * Walk the Caddyfile and pull every `(name) { ... }` block.
     *
     * @return list<array{name: string, body: string, raw: string}>
     */
    private function findSnippetBlocks(string $contents): array
    {
        $out = [];
        if (preg_match_all('/^\s*\(([A-Za-z0-9_.-]+)\)\s*\{/m', $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        foreach ($matches[0] ?? [] as $i => $headerMatch) {
            $name = $matches[1][$i][0];
            $rawStart = $headerMatch[1];
            $bodyStart = $rawStart + strlen($headerMatch[0]);
            $end = $this->findMatchingClose($contents, $bodyStart);
            if ($end === null) {
                continue;
            }
            $body = substr($contents, $bodyStart, $end - $bodyStart);
            $raw = substr($contents, $rawStart, $end - $rawStart + 1);
            $out[] = [
                'name' => $name,
                'body' => $this->stripCommonIndent($body),
                'raw' => $raw,
            ];
        }

        return $out;
    }

    private function findMatchingClose(string $contents, int $offset): ?int
    {
        $depth = 1;
        $len = strlen($contents);
        for ($i = $offset; $i < $len; $i++) {
            $c = $contents[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            } elseif ($c === '#') {
                $eol = strpos($contents, "\n", $i);
                if ($eol === false) {
                    break;
                }
                $i = $eol;
            }
        }

        return null;
    }

    /**
     * Strip the common leading indent off a snippet body so the editor
     * textarea isn't filled with tabs. The body is re-indented on save.
     */
    private function stripCommonIndent(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        // Strip leading and trailing blank lines for cleaner display.
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return '';
        }
        $indents = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^([\t ]+)/', $line, $m) === 1) {
                $indents[] = strlen($m[1]);
            } else {
                $indents[] = 0;
            }
        }
        $common = $indents === [] ? 0 : min($indents);
        if ($common === 0) {
            return implode("\n", $lines);
        }

        return implode("\n", array_map(fn (string $l): string => substr($l, $common) ?: '', $lines));
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents, string $reason): void
    {
        $emit->step('caddy-snippets', 'Staging new Caddyfile to /tmp ('.$reason.')');
        $tmpRemote = '/tmp/dply-caddyfile.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new Caddyfile');
            throw new \RuntimeException('Failed to stage the new Caddyfile.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('caddy-snippets', 'Snapshotting current Caddyfile to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('caddy-snippets', 'Installing new Caddyfile at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous Caddyfile left in place');
            throw new \RuntimeException('Failed to install the new Caddyfile.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('caddy-snippets', 'Validating with `caddy validate`');
        $validate = $ssh->exec('sudo -n caddy validate --config '.escapeshellarg(self::REMOTE_PATH).' 2>&1; echo "__exit__:$?"', 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? \App\Models\ConsoleAction::LEVEL_WARN : \App\Models\ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->step('caddy-snippets', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous Caddyfile restored.');
            throw new \RuntimeException('Config validation failed; previous Caddyfile restored. caddy validate output:'."\n".trim($stripped));
        }
        $emit->success('Caddyfile validated.');

        $emit->step('caddy-snippets', 'Reloading Caddy');
        $reload = $ssh->exec('sudo -n systemctl reload caddy 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart caddy 2>&1', 30);
        }
    }
}
