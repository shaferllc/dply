<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write the server-level LiteSpeed cache module block in
 * `/usr/local/lsws/conf/httpd_config.conf`. Operates on the
 * `module cache { ... }` stanza only — every other directive in the file
 * is preserved byte-for-byte.
 *
 * Default values follow the LiteSpeed-recommended starter config for
 * shared-hosting style workloads (1 hour public TTL, query-string-aware,
 * private cache enabled). Operators tune from there via the UI.
 *
 * Save flow: read current file → rewrite (or inject) the module block →
 * atomic install via `install -m 0644 -T tmp dst` → `lshttpd -t` validate →
 * `systemctl reload lshttpd`. A failed validate restores the previous file
 * from the .bak snapshot we take on every save.
 */
class OpenLiteSpeedCacheModuleConfig
{
    private const REMOTE_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    /**
     * Param key → metadata used by the UI and the parser:
     *   - type: 'bool' | 'int' | 'lines' (newline-separated string list)
     *   - default: string value emitted when the operator hasn't set one
     *   - label: human label
     *   - help: tooltip / sub-label
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'enableCache' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Enable public cache',
            'help' => 'Master switch for caching public (anonymous) responses.',
        ],
        'enablePrivateCache' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Enable private cache',
            'help' => 'Per-user cache for authenticated requests. Keyed on the session cookie.',
        ],
        'expireInSeconds' => [
            'type' => 'int',
            'default' => '3600',
            'label' => 'Public cache TTL',
            'help' => 'Default lifetime (seconds) for public cache entries. Backend Cache-Control overrides this per response.',
        ],
        'privateExpireInSeconds' => [
            'type' => 'int',
            'default' => '600',
            'label' => 'Private cache TTL',
            'help' => 'Default lifetime (seconds) for per-user cache entries.',
        ],
        'maxStaleAge' => [
            'type' => 'int',
            'default' => '200',
            'label' => 'Stale-while-revalidate window',
            'help' => 'Seconds beyond TTL where a stale hit is served while LSCache refetches in the background.',
        ],
        'qsCache' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Query strings in cache key',
            'help' => 'Include the request query string in the cache key. Disable only if your app ignores query args.',
        ],
        'ignoreReqCacheCtrl' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Ignore request Cache-Control',
            'help' => 'When on, browser `Cache-Control: no-cache` headers are ignored. Recommended on for public sites.',
        ],
        'ignoreRespCacheCtrl' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'Ignore response Cache-Control',
            'help' => 'When on, the backend cannot opt a response out via Cache-Control. Leave off unless you know your stack.',
        ],
        'checkPublicCache' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Check public cache',
            'help' => 'Look up public cache on every request. Should match Enable public cache.',
        ],
        'checkPrivateCache' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Check private cache',
            'help' => 'Look up private cache on every request. Should match Enable private cache.',
        ],
        'noCacheDomain' => [
            'type' => 'lines',
            'default' => '',
            'label' => 'Excluded domains',
            'help' => 'One hostname per line. Requests to these hosts skip the cache entirely.',
        ],
        'noCacheUrl' => [
            'type' => 'lines',
            'default' => '',
            'label' => 'Excluded URL patterns',
            'help' => 'One URL prefix per line (e.g. `/wp-admin`, `/cart`). Matching requests skip the cache.',
        ],
    ];

    /**
     * Read the cache module block from the server. Returns a defaults-filled
     * array keyed by the PARAMS keys above. Missing directives use the
     * declared defaults so the UI always renders a populated form.
     *
     * @return array{values: array<string, string>, exists: bool, raw: string}
     */
    public function read(Server $server): array
    {
        $values = array_map(fn (array $meta): string => (string) $meta['default'], self::PARAMS);

        try {
            $ssh = new SshConnection($server);
            // sudo -n: the conf file is often root-owned 600/640. The deploy
            // user has passwordless sudo configured by the provisioner, so a
            // plain `cat` would silently return empty and we'd fall back to
            // defaults even when a cache block exists on disk.
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['values' => $values, 'exists' => false, 'raw' => '', 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['values' => $values, 'exists' => false, 'raw' => '', 'unreadable' => true];
        }

        $block = $this->extractCacheBlock($contents);
        if ($block === null) {
            return ['values' => $values, 'exists' => false, 'raw' => '', 'unreadable' => false];
        }

        foreach (self::PARAMS as $key => $meta) {
            if ($meta['type'] === 'lines') {
                $values[$key] = implode("\n", $this->extractRepeatedDirective($block, $key));
            } else {
                $parsed = $this->extractScalarDirective($block, $key);
                if ($parsed !== null) {
                    $values[$key] = $parsed;
                }
            }
        }

        return ['values' => $values, 'exists' => true, 'raw' => $block, 'unreadable' => false];
    }

    /**
     * Render the cache-module block for the given form values. Public so
     * tests can assert the formatter output without going through SSH.
     *
     * @param  array<string, string>  $values
     */
    public function renderBlock(array $values): string
    {
        $lines = ['module cache {'];
        foreach (self::PARAMS as $key => $meta) {
            $raw = $values[$key] ?? $meta['default'];

            if ($meta['type'] === 'lines') {
                foreach (preg_split('/\R/', (string) $raw) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $lines[] = '  '.$key.'  '.$line;
                }

                continue;
            }

            $normalized = $this->normalizeScalar($meta['type'], $raw, $meta['default']);
            $lines[] = '  '.$key.'  '.$normalized;
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /**
     * Atomically replace (or inject) the cache-module block, validate with
     * `lshttpd -t`, and gracefully reload. On validation failure, the
     * previous file is restored from the .bak snapshot we take first.
     *
     * If an emitter is supplied (Livewire flow), each stage emits a step
     * line so the operator sees the work progress in the ConsoleAction
     * banner instead of staring at a spinner.
     *
     * @param  array<string, string>  $values
     *
     * @throws \RuntimeException when validate or reload fails
     */
    public function save(Server $server, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);

        $ssh = new SshConnection($server);

        $emit->step('ols-cache', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        $block = $this->renderBlock($values);
        $hadBlock = $this->extractCacheBlock($contents) !== null;
        if ($hadBlock) {
            $emit->info('Existing module cache block found — replacing in place.');
            $newContents = preg_replace('/^module\s+cache\s*\{.*?^\}\s*$/sm', rtrim($block, "\n"), $contents, 1);
        } else {
            $emit->info('No module cache block on disk — appending a new one.');
            $newContents = rtrim($contents, "\n")."\n\n".$block;
        }

        if (! is_string($newContents) || $newContents === '') {
            $emit->error('Failed to render the new httpd_config.conf');
            throw new \RuntimeException('Failed to render the new httpd_config.conf.');
        }

        $emit->step('ols-cache', 'Staging new config to /tmp');
        $tmpRemote = '/tmp/dply-httpd_config.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $writeCmd = sprintf(
            'printf %s | base64 -d | sudo -n tee %s > /dev/null',
            escapeshellarg($encoded),
            escapeshellarg($tmpRemote),
        );
        $ssh->exec($writeCmd, 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new config on the server');
            throw new \RuntimeException('Failed to stage the new config on the server.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('ols-cache', 'Snapshotting current config to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('ols-cache', 'Installing new config at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous config left in place');
            throw new \RuntimeException('Failed to install the new config on the server.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('ols-cache', 'Validating with `lshttpd -t`');
        $validate = $ssh->exec('sudo -n /usr/local/lsws/bin/lshttpd -t 2>&1; echo "__exit__:$?"', 30);
        $exit = $this->parseExitMarker($validate);
        $validateOutput = trim($this->stripExitMarker($validate));
        if ($validateOutput !== '') {
            foreach (preg_split('/\R/', $validateOutput) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
                }
            }
        }
        if ($exit !== 0) {
            $emit->step('ols-cache', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous config restored.');
            throw new \RuntimeException('Config validation failed; previous config restored. lshttpd -t output:'."\n".$validateOutput);
        }
        $emit->success('Config validated.');

        $emit->step('ols-cache', 'Reloading OpenLiteSpeed (`systemctl reload lshttpd`)');
        $reload = $ssh->exec('sudo -n systemctl reload lshttpd 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = $this->parseExitMarker($reload);
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart lshttpd 2>&1', 30);
        }
        $emit->success('OpenLiteSpeed reloaded with the new cache config.');
    }

    private function parseExitMarker(string $output): int
    {
        if (preg_match('/__exit__:(\d+)\s*$/', $output, $m) === 1) {
            return (int) $m[1];
        }

        return 1;
    }

    private function stripExitMarker(string $output): string
    {
        return (string) preg_replace('/__exit__:\d+\s*$/', '', $output);
    }

    /**
     * Pull the `module cache { ... }` block out of the full config. Returns
     * null when the block isn't present. The opening line, body, and
     * closing brace are returned verbatim so callers can substitute exactly.
     */
    private function extractCacheBlock(string $contents): ?string
    {
        if (preg_match('/^[\t ]*module\s+cache\s*\{.*?^[\t ]*\}/sm', $contents, $m) !== 1) {
            return null;
        }

        return $m[0];
    }

    private function extractScalarDirective(string $block, string $key): ?string
    {
        if (preg_match('/^[\t ]*'.preg_quote($key, '/').'\s+(\S.*?)\s*$/m', $block, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * @return list<string>
     */
    private function extractRepeatedDirective(string $block, string $key): array
    {
        if (preg_match_all('/^[\t ]*'.preg_quote($key, '/').'\s+(\S.*?)\s*$/m', $block, $matches) === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $matches[1] ?? []), fn (string $v) => $v !== ''));
    }

    private function normalizeScalar(string $type, string $raw, string $default): string
    {
        $raw = trim($raw);
        if ($type === 'bool') {
            return in_array($raw, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        }
        if ($type === 'int') {
            if (! preg_match('/^-?\d+$/', $raw)) {
                return $default;
            }

            return $raw;
        }

        return $raw;
    }
}
