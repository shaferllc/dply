<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write per-vhost `vhconf.conf` files for OpenLiteSpeed sites.
 *
 * Each vhost is wired into `httpd_config.conf` via a `vhTemplate <name>` block
 * which points at `/usr/local/lsws/conf/vhosts/<basename>/vhconf.conf`. The
 * actual vhost directives (docRoot, index, logs, cache, gzip/br) live in
 * that per-vhost file, not in httpd_config.conf.
 *
 * dply OWNS these files — they're regenerated end-to-end by the site
 * provisioner on every site Apply and webserver switch. Edits made here
 * persist until the next provisioner run; the UI warns the operator so
 * they can either avoid the round-trip or pin their changes elsewhere.
 *
 * Save flow: per-vhost snapshot → atomic install → single server-wide
 * `lshttpd -t` → reload. On validation failure, every modified file is
 * restored from its .dply-bak.<ts> snapshot.
 */
class OpenLiteSpeedVhostsConfig
{
    private const HTTPD_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    /**
     * Editable directives. Each entry declares:
     *   - context: top-level scalar ('top'), or the block name it lives in
     *     ('general', 'index', 'errorlog', 'cache')
     *   - type: 'bool' | 'int' | 'string' | 'list' (comma-separated)
     *   - default: value used when missing
     *   - label, help: UI metadata
     *
     * @var array<string, array{context: string, directive: string, type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'docRoot' => [
            'context' => 'top',
            'directive' => 'docRoot',
            'type' => 'string',
            'default' => '$VH_ROOT/public/',
            'label' => 'Document root',
            'help' => 'Filesystem path the vhost serves. `$VH_ROOT` is the site\'s repository root. Changing this re-points the site to a different subdirectory.',
        ],
        'indexUseServer' => [
            'context' => 'index',
            'directive' => 'useServer',
            'type' => 'int',
            'default' => '0',
            'label' => 'Index — use server defaults',
            'help' => '0 = override server-level index files with the list below. 1 = use the server-wide list only. 2 = merge.',
        ],
        'indexFiles' => [
            'context' => 'index',
            'directive' => 'indexFiles',
            'type' => 'list',
            'default' => 'index.php, index.html',
            'label' => 'Index files',
            'help' => 'Comma-separated. OLS tries each filename in order when a request hits a directory.',
        ],
        'errorlogLevel' => [
            'context' => 'errorlog',
            'directive' => 'logLevel',
            'type' => 'string',
            'default' => 'WARN',
            'label' => 'Error log level',
            'help' => 'One of DEBUG, INFO, NOTICE, WARN, ERROR. WARN is the dply default.',
        ],
        'errorlogRolling' => [
            'context' => 'errorlog',
            'directive' => 'rollingSize',
            'type' => 'string',
            'default' => '10M',
            'label' => 'Error log rotation size',
            'help' => 'OLS rotates the error log when it crosses this size. e.g. `10M`, `100M`, `1G`.',
        ],
        'accesslogRolling' => [
            'context' => 'accesslog',
            'directive' => 'rollingSize',
            'type' => 'string',
            'default' => '10M',
            'label' => 'Access log rotation size',
            'help' => 'Same as errorlog rotation, but for the access log.',
        ],
        'accesslogKeepDays' => [
            'context' => 'accesslog',
            'directive' => 'keepDays',
            'type' => 'int',
            'default' => '30',
            'label' => 'Access log retention (days)',
            'help' => 'Rotated access log archives older than this are deleted.',
        ],
        'accesslogCompress' => [
            'context' => 'accesslog',
            'directive' => 'compressArchive',
            'type' => 'bool',
            'default' => '0',
            'label' => 'Compress rotated access logs',
            'help' => 'gzip rotated access log archives. Saves disk; CPU cost is minor.',
        ],
        'enableGzip' => [
            'context' => 'general',
            'directive' => 'enableGzip',
            'type' => 'bool',
            'default' => '1',
            'label' => 'Enable gzip',
            'help' => 'Compress responses with gzip when the client supports it.',
        ],
        'enableBr' => [
            'context' => 'general',
            'directive' => 'enableBr',
            'type' => 'bool',
            'default' => '1',
            'label' => 'Enable brotli',
            'help' => 'Compress responses with Brotli when the client supports it (usually all modern browsers).',
        ],
        'cacheEnable' => [
            'context' => 'cache',
            'directive' => 'enableCache',
            'type' => 'bool',
            'default' => '1',
            'label' => 'Per-vhost: enable cache',
            'help' => 'Override server-level enableCache for this vhost only. Turning off means LSCache is disabled even when on at the server level.',
        ],
        'cacheTtl' => [
            'context' => 'cache',
            'directive' => 'expireInSeconds',
            'type' => 'int',
            'default' => '3600',
            'label' => 'Per-vhost: public cache TTL (s)',
            'help' => 'Default cache lifetime for this vhost. Useful when one site needs a different TTL than the server default.',
        ],
    ];

    /**
     * @return array{vhosts: list<array{name: string, conf_path: string, vh_root: ?string, domains: list<string>, values: array<string, string>, raw: string, unreadable: bool}>, unreadable_httpd: bool}
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $httpd = $ssh->exec('sudo -n cat '.escapeshellarg(self::HTTPD_PATH).' 2>/dev/null', 15);
            if ($httpd === '' || $ssh->lastExecExitCode() !== 0) {
                return ['vhosts' => [], 'unreadable_httpd' => true];
            }
        } catch (\Throwable) {
            return ['vhosts' => [], 'unreadable_httpd' => true];
        }

        $vhosts = [];
        foreach ($this->findVhostTemplates($httpd) as $vhBlock) {
            $name = $this->extractTemplateName($vhBlock);
            $confPath = $this->extractScalarOutside($vhBlock, 'templateFile');
            $vhRoot = $this->extractScalarOutside($vhBlock, 'vhRoot');
            $members = $this->extractMembers($vhBlock);
            if ($name === null || $confPath === null) {
                continue;
            }

            // Pull the per-vhost vhconf.conf — defaults to dply provisioner's
            // path when the template doesn't override it. Skip with the
            // 'unreadable' flag if we can't read so the UI keeps the entry.
            $vhconf = $ssh->exec('sudo -n cat '.escapeshellarg($confPath).' 2>/dev/null', 10);
            $unreadable = $vhconf === '' || $ssh->lastExecExitCode() !== 0;

            $values = array_map(fn (array $meta): string => (string) $meta['default'], self::PARAMS);
            if (! $unreadable) {
                foreach (self::PARAMS as $key => $meta) {
                    $parsed = $this->extractDirective($vhconf, $meta['context'], $meta['directive']);
                    if ($parsed !== null) {
                        $values[$key] = $parsed;
                    }
                }
            }

            $vhosts[] = [
                'name' => $name,
                'conf_path' => $confPath,
                'vh_root' => $vhRoot,
                'domains' => $members,
                'values' => $values,
                'raw' => $unreadable ? '' : $vhconf,
                'unreadable' => $unreadable,
            ];
        }

        return ['vhosts' => $vhosts, 'unreadable_httpd' => false];
    }

    /**
     * Save edits across multiple vhconf.conf files. Snapshots each before
     * writing. After all files are written, validates with `lshttpd -t`
     * once; on failure restores every snapshot.
     *
     * @param  array<string, array{conf_path: string, values: array<string, string>}>  $updates  Keyed by vhost name
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, array $updates, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        if ($updates === []) {
            $emit->warn('No vhost updates supplied.');

            return;
        }

        $stamps = []; // confPath => bak path, for rollback
        $written = 0;
        $tsSuffix = now()->format('YmdHis');

        try {
            foreach ($updates as $vhostName => $payload) {
                $confPath = (string) ($payload['conf_path'] ?? '');
                if ($confPath === '' || ! str_starts_with($confPath, '/')) {
                    $emit->warn('Skipping `'.$vhostName.'` — missing conf_path.');

                    continue;
                }

                $emit->step('ols-vhosts', 'Reading '.$confPath);
                $current = $ssh->exec('sudo -n cat '.escapeshellarg($confPath), 10);
                if ($ssh->lastExecExitCode() !== 0 || $current === '') {
                    $emit->warn('Could not read '.$confPath.' — skipping.');

                    continue;
                }

                $merged = $this->mergeIntoVhconf($current, (array) ($payload['values'] ?? []));
                if ($merged === $current) {
                    $emit->info('No changes for '.$vhostName.'.');

                    continue;
                }

                $emit->step('ols-vhosts', 'Staging '.$confPath);
                $tmpRemote = '/tmp/dply-vhconf.'.bin2hex(random_bytes(6));
                $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg(base64_encode($merged)), escapeshellarg($tmpRemote)), 10);
                if ($ssh->lastExecExitCode() !== 0) {
                    throw new \RuntimeException('Failed to stage '.$confPath.' on the server.');
                }

                $bak = $confPath.'.dply-bak.'.$tsSuffix;
                $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($confPath), escapeshellarg($bak)), 5);
                $stamps[$confPath] = $bak;

                $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg($confPath)), 5);
                $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
                if ($ssh->lastExecExitCode() !== 0) {
                    throw new \RuntimeException('Failed to install '.$confPath.'.');
                }

                $written++;
                $emit->info('Wrote '.$confPath);
            }

            if ($written === 0) {
                $emit->info('Nothing changed; skipping validate + reload.');

                return;
            }

            $emit->step('ols-vhosts', 'Validating with `lshttpd -t`');
            $validate = $ssh->exec('sudo -n /usr/local/lsws/bin/lshttpd -t 2>&1; echo "__exit__:$?"', 30);
            $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
            $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
            foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
                }
            }
            if ($exit !== 0) {
                $emit->step('ols-vhosts', 'Validation failed — restoring snapshots');
                foreach ($stamps as $confPath => $bak) {
                    $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg($confPath)), 5);
                }
                throw new \RuntimeException('Config validation failed; all '.count($stamps).' vhost file(s) restored. lshttpd -t output:'."\n".trim($stripped));
            }
            $emit->success('Config validated.');

            $emit->step('ols-vhosts', 'Reloading OpenLiteSpeed');
            $reload = $ssh->exec('sudo -n systemctl reload lshttpd 2>&1; echo "__exit__:$?"', 20);
            $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
            if ($reloadExit !== 0) {
                $emit->warn('Reload returned non-zero — falling back to restart.');
                $ssh->exec('sudo -n systemctl restart lshttpd 2>&1', 30);
            }
            $emit->success('OpenLiteSpeed reloaded with the new vhost config.');
        } catch (\Throwable $e) {
            // Any failure before validate already wrote some files — restore
            // them all from snapshots so the operator isn't left half-edited.
            if ($stamps !== []) {
                $emit->warn('Restoring '.count($stamps).' file(s) from snapshot.');
                foreach ($stamps as $confPath => $bak) {
                    $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg($confPath)), 5);
                }
            }
            throw $e;
        }
    }

    /**
     * Merge form values into a vhconf.conf string. Walks each managed
     * directive and either rewrites the matching line (preserving its
     * indentation) or appends to the matching block (or creates the block
     * if missing).
     *
     * @param  array<string, string>  $values
     */
    private function mergeIntoVhconf(string $contents, array $values): string
    {
        foreach (self::PARAMS as $key => $meta) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $rawValue = $this->normalizeForWrite($meta['type'], (string) $values[$key], $meta['default']);
            if ($meta['context'] === 'top') {
                $contents = $this->upsertTopScalar($contents, $meta['directive'], $rawValue);
            } else {
                $contents = $this->upsertBlockScalar($contents, $meta['context'], $meta['directive'], $rawValue);
            }
        }

        return $contents;
    }

    private function upsertTopScalar(string $contents, string $directive, string $value): string
    {
        $pattern = '/^([\t ]*)'.preg_quote($directive, '/').'\s+\S.*$/m';
        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, '$1'.$directive.'                   '.$value, $contents, 1);
        }

        // Append at top of file under a "Managed by dply" comment so the
        // operator can see it landed.
        return rtrim($contents, "\n")."\n".$directive.'                   '.$value."\n";
    }

    private function upsertBlockScalar(string $contents, string $blockName, string $directive, string $value): string
    {
        // Locate the block. Block headers in vhconf.conf can be:
        //   index  {       → simple
        //   errorlog $VH_ROOT/logs/error.log {  → with argument
        //   accesslog $VH_ROOT/logs/access.log {
        $blockRe = '/^([\t ]*)'.preg_quote($blockName, '/').'\b[^\n{]*\{(.*?)^[\t ]*\}/sm';
        if (preg_match($blockRe, $contents, $m, PREG_OFFSET_CAPTURE) === 1) {
            $body = $m[2][0];
            $bodyIndent = $m[1][0].'  ';
            $directiveRe = '/^([\t ]*)'.preg_quote($directive, '/').'\s+\S.*$/m';
            if (preg_match($directiveRe, $body) === 1) {
                $newBody = (string) preg_replace($directiveRe, '$1'.$directive.'              '.$value, $body, 1);
            } else {
                $newBody = rtrim($body, "\n")."\n".$bodyIndent.$directive.'              '.$value."\n";
            }
            $newBlock = str_replace($body, $newBody, $m[0][0]);

            return substr_replace($contents, $newBlock, $m[0][1], strlen($m[0][0]));
        }

        // Block missing — append a fresh one at the end of the file.
        $newBlock = "\n".$blockName.'  {'."\n".'  '.$directive.'              '.$value."\n".'}'."\n";

        return rtrim($contents, "\n").$newBlock;
    }

    private function normalizeForWrite(string $type, string $raw, string $default): string
    {
        $raw = trim($raw);
        if ($type === 'bool') {
            return in_array($raw, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        }
        if ($type === 'int') {
            return preg_match('/^-?\d+$/', $raw) === 1 ? $raw : $default;
        }
        if ($type === 'list') {
            $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $raw) ?: []), fn ($v) => $v !== ''));

            return implode(', ', $parts);
        }

        return $raw;
    }

    /**
     * @return list<string>
     */
    private function findVhostTemplates(string $contents): array
    {
        if (preg_match_all('/^[\t ]*vhTemplate\s+\S+\s*\{.*?^[\t ]*\}/sm', $contents, $m) === false) {
            return [];
        }

        return array_values($m[0] ?? []);
    }

    private function extractTemplateName(string $block): ?string
    {
        if (preg_match('/^[\t ]*vhTemplate\s+(\S+)/m', $block, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function extractScalarOutside(string $block, string $key): ?string
    {
        if (preg_match('/^[\t ]*'.preg_quote($key, '/').'\s+(\S.*?)\s*$/m', $block, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * Member directive carries vhost↔domain mapping inside vhTemplate. Each
     * `member <vhost> { ... }` block lists the hostnames associated with
     * the template instance. We surface them so the UI can show which
     * domains the vhost serves.
     *
     * @return list<string>
     */
    private function extractMembers(string $block): array
    {
        $domains = [];
        if (preg_match_all('/^[\t ]*member\s+\S+\s*\{(.*?)^[\t ]*\}/sm', $block, $matches)) {
            foreach ($matches[1] ?? [] as $body) {
                if (preg_match_all('/^[\t ]*vhDomain\s+(\S.*?)\s*$/m', $body, $dm)) {
                    foreach ($dm[1] as $d) {
                        foreach (preg_split('/\s*,\s*/', $d) ?: [] as $one) {
                            if ($one !== '') {
                                $domains[] = $one;
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Pull a directive value out of vhconf.conf. `top` context searches the
     * whole file; named contexts search inside the matching `<context> { ... }`
     * block (with optional argument header like `errorlog <path> { ... }`).
     */
    private function extractDirective(string $contents, string $context, string $directive): ?string
    {
        if ($context === 'top') {
            if (preg_match('/^[\t ]*'.preg_quote($directive, '/').'\s+(\S.*?)\s*$/m', $contents, $m) !== 1) {
                return null;
            }

            return trim($m[1]);
        }

        $blockRe = '/^[\t ]*'.preg_quote($context, '/').'\b[^\n{]*\{(.*?)^[\t ]*\}/sm';
        if (preg_match($blockRe, $contents, $bm) !== 1) {
            return null;
        }
        $body = $bm[1];
        if (preg_match('/^[\t ]*'.preg_quote($directive, '/').'\s+(\S.*?)\s*$/m', $body, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }
}
