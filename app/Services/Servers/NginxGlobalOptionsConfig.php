<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write nginx's top-level server config in /etc/nginx/nginx.conf.
 * Operates on three families of directives:
 *
 *   - top-level scalars (user, worker_processes, worker_rlimit_nofile, ...)
 *   - inside `events { ... }`  (worker_connections, multi_accept, use)
 *   - inside `http { ... }`    (sendfile, gzip, keepalive_timeout, ...)
 *
 * Site blocks (server { }) and includes pass through byte-for-byte —
 * dply rewrites per-site files via the per-site provisioner.
 *
 * Save pipeline mirrors the Caddy / OLS services: snapshot →
 * atomic install → `nginx -t` → graceful reload, with auto-restore
 * from the .dply-bak.<ts> snapshot on validation failure.
 */
class NginxGlobalOptionsConfig
{
    private const REMOTE_PATH = '/etc/nginx/nginx.conf';

    /**
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const TOP_PARAMS = [
        'user' => [
            'type' => 'string',
            'default' => 'www-data',
            'label' => 'Worker user',
            'help' => 'Unix user nginx workers run as. dply provisions `www-data`; change only if you\'re running a custom user.',
        ],
        'worker_processes' => [
            'type' => 'string',
            'default' => 'auto',
            'label' => 'Worker processes',
            'help' => '`auto` (one per CPU core) or a fixed integer. `auto` is the sane default for almost every workload.',
        ],
        'worker_rlimit_nofile' => [
            'type' => 'int',
            'default' => '65535',
            'label' => 'Worker open file limit',
            'help' => 'Max open file descriptors per worker. Must be ≤ system ulimit. 65535 is a sane default.',
        ],
        'error_log_level' => [
            'type' => 'string',
            'default' => 'notice',
            'label' => 'Error log level',
            'help' => 'One of `debug`, `info`, `notice`, `warn`, `error`, `crit`, `alert`, `emerg`.',
        ],
    ];

    /**
     * `events { ... }` directives.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const EVENTS_PARAMS = [
        'worker_connections' => [
            'type' => 'int',
            'default' => '1024',
            'label' => 'Worker connections',
            'help' => 'Max simultaneous connections per worker. Total cap = workers × this. Bump to 4096+ for high-traffic.',
        ],
        'multi_accept' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'Multi-accept',
            'help' => 'Workers accept all pending connections at once. Marginal win on busy boxes.',
        ],
        'use' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Connection processing method',
            'help' => 'One of `epoll` (Linux), `kqueue` (BSD/macOS), `poll`, `select`. Blank lets nginx pick.',
        ],
    ];

    /**
     * `http { ... }` directives — a curated slice that operators actually tune.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const HTTP_PARAMS = [
        'sendfile' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'sendfile',
            'help' => 'Use the kernel sendfile(2) syscall. Off only for shared NFS / non-local storage where it\'s broken.',
        ],
        'tcp_nopush' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'tcp_nopush',
            'help' => 'Send headers in one packet. Pairs with sendfile to reduce small-packet count.',
        ],
        'tcp_nodelay' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'tcp_nodelay',
            'help' => 'Disable Nagle\'s algorithm — flush small writes immediately. Better latency at the cost of slightly more packets.',
        ],
        'keepalive_timeout' => [
            'type' => 'int',
            'default' => '65',
            'label' => 'Keepalive timeout (s)',
            'help' => 'Seconds an idle keep-alive connection stays open. 65s is the standard.',
        ],
        'client_max_body_size' => [
            'type' => 'string',
            'default' => '64M',
            'label' => 'Max upload size',
            'help' => 'Rejects requests larger than this. Bump for big file uploads (e.g. `100M`, `1G`).',
        ],
        'server_tokens' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'Server tokens (version in headers)',
            'help' => 'When on, nginx version leaks into Server: headers and error pages. Recommended off.',
        ],
        'gzip' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'gzip compression',
            'help' => 'Compress responses with gzip when the client supports it.',
        ],
        'gzip_vary' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Vary: Accept-Encoding header',
            'help' => 'Add `Vary: Accept-Encoding` so caches differentiate gzipped/non-gzipped responses.',
        ],
        'types_hash_max_size' => [
            'type' => 'int',
            'default' => '4096',
            'label' => 'Types hash max size',
            'help' => 'Hash table size for the MIME types map. Bump if nginx logs "types_hash_max_size or types_hash_bucket_size".',
        ],
    ];

    /**
     * @return array{values: array<string, string>, exists: bool, unreadable: bool, raw: string}
     */
    public function read(Server $server): array
    {
        $values = $this->defaults();

        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['values' => $values, 'exists' => false, 'unreadable' => true, 'raw' => ''];
            }
        } catch (\Throwable) {
            return ['values' => $values, 'exists' => false, 'unreadable' => true, 'raw' => ''];
        }

        // Top-level scalars: parse at depth 0.
        foreach (self::TOP_PARAMS as $key => $meta) {
            if ($key === 'error_log_level') {
                // error_log is `error_log <path> <level>;`. We surface only
                // the level (path is dply-managed).
                if (preg_match('/^\s*error_log\s+\S+\s+(\S+);/m', $contents, $m) === 1) {
                    $values['error_log_level'] = trim($m[1]);
                }

                continue;
            }
            $v = $this->extractTopScalar($contents, $key);
            if ($v !== null) {
                $values[$key] = $v;
            }
        }

        // events { ... }
        $events = $this->extractNamedBlockBody($contents, 'events');
        if ($events !== null) {
            foreach (self::EVENTS_PARAMS as $key => $meta) {
                $v = $this->extractDirective($events, $key, $meta['type']);
                if ($v !== null) {
                    $values['events_'.$key] = $v;
                }
            }
        }

        // http { ... }
        $http = $this->extractNamedBlockBody($contents, 'http');
        if ($http !== null) {
            foreach (self::HTTP_PARAMS as $key => $meta) {
                $v = $this->extractDirective($http, $key, $meta['type']);
                if ($v !== null) {
                    $values['http_'.$key] = $v;
                }
            }
        }

        return ['values' => $values, 'exists' => true, 'unreadable' => false, 'raw' => $contents];
    }

    /**
     * @param  array<string, string>  $values
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('nginx-globals', 'Reading current nginx.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read nginx.conf from the server.');
        }

        $newContents = $contents;

        // Top-level scalars (skip blanks for optional ones — but for `use`
        // we treat blank as "remove", for everything else we upsert).
        foreach (self::TOP_PARAMS as $key => $meta) {
            if ($key === 'error_log_level') {
                $level = trim((string) ($values['error_log_level'] ?? $meta['default']));
                if ($level === '') {
                    continue;
                }
                $newContents = (string) preg_replace_callback(
                    '/^([\t ]*)error_log(\s+)(\S+)(\s+)(\S+);/m',
                    fn (array $m): string => $m[1].'error_log'.$m[2].$m[3].$m[4].$level.';',
                    $newContents,
                    1,
                );

                continue;
            }
            $val = trim((string) ($values[$key] ?? $meta['default']));
            if ($val === '') {
                continue;
            }
            $newContents = $this->upsertTopScalar($newContents, $key, $val);
        }

        // events { ... }
        $newContents = $this->upsertBlockDirectives($newContents, 'events', $values, self::EVENTS_PARAMS, 'events_');

        // http { ... }
        $newContents = $this->upsertBlockDirectives($newContents, 'http', $values, self::HTTP_PARAMS, 'http_');

        if ($newContents === $contents) {
            $emit->info('No changes to nginx.conf.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents);
        $emit->success('nginx reloaded with the new global options.');
    }

    private function upsertTopScalar(string $contents, string $key, string $value): string
    {
        $pattern = '/^([\t ]*)'.preg_quote($key, '/').'\s+[^;]*;/m';
        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, '$1'.$key.' '.$value.';', $contents, 1);
        }

        // Insert before the first `events {` (or at end of file if not found).
        $pos = strpos($contents, 'events');
        if ($pos !== false) {
            return substr($contents, 0, $pos).$key.' '.$value.";\n\n".substr($contents, $pos);
        }

        return rtrim($contents, "\n")."\n".$key.' '.$value.";\n";
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, array{type: string, default: string, label: string, help: string}>  $params
     */
    private function upsertBlockDirectives(string $contents, string $blockName, array $values, array $params, string $keyPrefix): string
    {
        $blockMatch = $this->findNamedBlock($contents, $blockName);
        if ($blockMatch === null) {
            // Block doesn't exist — build a fresh one with non-default values and append.
            $newDirectives = [];
            foreach ($params as $key => $meta) {
                $val = trim((string) ($values[$keyPrefix.$key] ?? ''));
                if ($val === '' || ($meta['type'] === 'bool' && $val === '0')) {
                    continue;
                }
                $newDirectives[] = "\t".$key.' '.$this->normaliseValueForWrite($meta['type'], $val).';';
            }
            if ($newDirectives === []) {
                return $contents;
            }

            return rtrim($contents, "\n")."\n\n".$blockName." {\n".implode("\n", $newDirectives)."\n}\n";
        }

        // Block exists — rewrite directives in place (or append before the close brace).
        [$bodyStart, $bodyEnd] = $blockMatch;
        $body = substr($contents, $bodyStart, $bodyEnd - $bodyStart);
        $newBody = $body;
        foreach ($params as $key => $meta) {
            if (! array_key_exists($keyPrefix.$key, $values)) {
                continue;
            }
            $rawVal = trim((string) $values[$keyPrefix.$key]);
            $writeVal = $this->normaliseValueForWrite($meta['type'], $rawVal);

            // Skip emitting bool=0 (i.e. directive turned off) — instead REMOVE
            // an existing line, since nginx defaults vary and `off` may not be
            // legal for every directive in our set.
            if ($meta['type'] === 'bool' && $writeVal === 'off') {
                $newBody = (string) preg_replace('/^[\t ]*'.preg_quote($key, '/').'\s+[^;]*;\R?/m', '', $newBody);

                continue;
            }
            // Optional strings (e.g. `use`) — skip empty.
            if ($meta['type'] === 'string' && $rawVal === '') {
                continue;
            }

            $directivePattern = '/^([\t ]*)'.preg_quote($key, '/').'\s+[^;]*;/m';
            if (preg_match($directivePattern, $newBody) === 1) {
                $newBody = (string) preg_replace($directivePattern, '$1'.$key.' '.$writeVal.';', $newBody, 1);
            } else {
                $newBody = rtrim($newBody, "\n")."\n\t".$key.' '.$writeVal.";\n";
            }
        }

        return substr($contents, 0, $bodyStart).$newBody.substr($contents, $bodyEnd);
    }

    private function normaliseValueForWrite(string $type, string $raw): string
    {
        $raw = trim($raw);
        if ($type === 'bool') {
            return in_array($raw, ['1', 'true', 'on', 'yes'], true) ? 'on' : 'off';
        }
        if ($type === 'int') {
            return preg_match('/^-?\d+$/', $raw) === 1 ? $raw : '0';
        }

        return $raw;
    }

    private function extractTopScalar(string $contents, string $key): ?string
    {
        // Match `<key> <value>;` at depth 0. We walk braces ourselves.
        $depth = 0;
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trim = trim($line);
            $depthChange = substr_count($line, '{') - substr_count($line, '}');
            if ($depth === 0 && preg_match('/^'.preg_quote($key, '/').'\s+([^;]+);/', $trim, $m) === 1) {
                return trim($m[1]);
            }
            $depth += $depthChange;
        }

        return null;
    }

    private function extractDirective(string $blockBody, string $key, string $type): ?string
    {
        if (preg_match('/^[\t ]*'.preg_quote($key, '/').'\s+([^;]+);/m', $blockBody, $m) !== 1) {
            return null;
        }
        $raw = trim($m[1]);
        if ($type === 'bool') {
            return in_array($raw, ['on', '1', 'true', 'yes'], true) ? '1' : '0';
        }

        return $raw;
    }

    private function extractNamedBlockBody(string $contents, string $blockName): ?string
    {
        $match = $this->findNamedBlock($contents, $blockName);
        if ($match === null) {
            return null;
        }
        [$start, $end] = $match;

        return substr($contents, $start, $end - $start);
    }

    /**
     * @return array{0: int, 1: int}|null [body start offset, body end offset] for `<blockName> { ... }`
     */
    private function findNamedBlock(string $contents, string $blockName): ?array
    {
        if (preg_match('/^[\t ]*'.preg_quote($blockName, '/').'\s*\{/m', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $bodyStart = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($contents);
        for ($i = $bodyStart; $i < $len; $i++) {
            $c = $contents[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$bodyStart, $i];
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
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $out = [];
        foreach (self::TOP_PARAMS as $key => $meta) {
            $out[$key] = (string) $meta['default'];
        }
        foreach (self::EVENTS_PARAMS as $key => $meta) {
            $out['events_'.$key] = (string) $meta['default'];
        }
        foreach (self::HTTP_PARAMS as $key => $meta) {
            $out['http_'.$key] = (string) $meta['default'];
        }

        return $out;
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents): void
    {
        $emit->step('nginx-globals', 'Staging new nginx.conf to /tmp');
        $tmpRemote = '/tmp/dply-nginx.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new nginx.conf');
            throw new \RuntimeException('Failed to stage the new nginx.conf.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('nginx-globals', 'Snapshotting current nginx.conf to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('nginx-globals', 'Installing new nginx.conf at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous nginx.conf left in place');
            throw new \RuntimeException('Failed to install the new nginx.conf.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('nginx-globals', 'Validating with `nginx -t`');
        $validate = $ssh->exec('sudo -n nginx -t 2>&1; echo "__exit__:$?"', 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->step('nginx-globals', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous nginx.conf restored.');
            throw new \RuntimeException('Config validation failed; previous nginx.conf restored. nginx -t output:'."\n".trim($stripped));
        }
        $emit->success('nginx.conf validated.');

        $emit->step('nginx-globals', 'Reloading nginx (`systemctl reload nginx`)');
        $reload = $ssh->exec('sudo -n systemctl reload nginx 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart nginx 2>&1', 30);
        }
    }
}
