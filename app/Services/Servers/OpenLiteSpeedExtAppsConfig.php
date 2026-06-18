<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write OpenLiteSpeed's `extprocessor <name> { ... }` blocks in
 * `/usr/local/lsws/conf/httpd_config.conf`. Each block represents one
 * external app — usually an LSAPI PHP worker pool — that vhosts proxy
 * to. dply's provisioner emits these on install; this service lets the
 * operator tune the tunable directives (maxConns, env, etc.) without
 * editing the file by hand.
 *
 * Identity fields (name, type, address, path) are returned for display
 * but not editable here — they're set when the PHP backend is provisioned
 * and changing them would desync the vhost references.
 *
 * Save flow: snapshot → atomic install → `lshttpd -t` → reload, with
 * auto-restore from .bak on validation failure. Mirrors
 * {@see OpenLiteSpeedCacheModuleConfig}.
 */
class OpenLiteSpeedExtAppsConfig
{
    private const REMOTE_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    /**
     * Identity directives — shown read-only in the UI. The provisioner
     * owns these; tampering re-points the vhost upstream to a broken
     * socket.
     *
     * @var list<string>
     */
    public const IDENTITY_KEYS = ['type', 'address', 'path'];

    /**
     * Editable directives → metadata for the UI.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'maxConns' => [
            'type' => 'int',
            'default' => '10',
            'label' => 'Max concurrent connections',
            'help' => 'Upper bound on simultaneous requests this worker pool handles. Higher = more PHP-FPM-equivalent workers; tune to RAM.',
        ],
        'instances' => [
            'type' => 'int',
            'default' => '1',
            'label' => 'Process instances',
            'help' => 'Number of worker processes spawned. Most pools run one instance and scale via maxConns child workers.',
        ],
        'initTimeout' => [
            'type' => 'int',
            'default' => '60',
            'label' => 'Init timeout (s)',
            'help' => 'Seconds OLS waits for a worker to come up before declaring it dead.',
        ],
        'retryTimeout' => [
            'type' => 'int',
            'default' => '0',
            'label' => 'Retry timeout (s)',
            'help' => 'Seconds OLS waits before re-trying a busy / failed worker. 0 = retry immediately.',
        ],
        'persistConn' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Persistent connection',
            'help' => 'Keep the LSAPI socket open between requests for lower per-request overhead.',
        ],
        'pcKeepAliveTimeout' => [
            'type' => 'int',
            'default' => '30',
            'label' => 'Persistent conn idle timeout (s)',
            'help' => 'How long an idle persistent connection survives before OLS closes it.',
        ],
        'respBuffer' => [
            'type' => 'int',
            'default' => '0',
            'label' => 'Response buffer',
            'help' => '0 = stream response (default), 1 = buffer in OLS before flushing. Buffering smooths backpressure but raises memory.',
        ],
        'autoStart' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Auto-start on demand',
            'help' => 'Spawn the worker on first request rather than running it permanently.',
        ],
        'runOnStartUp' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'Run on OLS startup',
            'help' => 'Pre-spawn the worker when OLS starts so the first request doesn\'t pay the cold-start cost.',
        ],
        'extMaxIdleTime' => [
            'type' => 'int',
            'default' => '-1',
            'label' => 'Max idle time (s)',
            'help' => 'Idle seconds before OLS reaps the worker. -1 = no reaping.',
        ],
        'priority' => [
            'type' => 'int',
            'default' => '0',
            'label' => 'Process priority',
            'help' => 'Unix nice value. 0 is default; -5 boosts priority, +5 lowers it.',
        ],
        'memSoftLimit' => [
            'type' => 'string',
            'default' => '2047M',
            'label' => 'Memory soft limit',
            'help' => 'Per-worker virtual memory soft limit (e.g. `512M`, `2G`). Worker is signalled when crossed.',
        ],
        'memHardLimit' => [
            'type' => 'string',
            'default' => '2047M',
            'label' => 'Memory hard limit',
            'help' => 'Per-worker hard memory ceiling; the kernel kills workers crossing this.',
        ],
        'env' => [
            'type' => 'lines',
            'default' => '',
            'label' => 'Environment variables',
            'help' => 'One KEY=VALUE per line. Common: PHP_LSAPI_CHILDREN, LSAPI_AVOID_FORK, LSAPI_KEEP_HTTP_REQUEST_TIME.',
        ],
    ];

    /**
     * Read all `extprocessor` blocks. Each entry includes parsed editable
     * values, identity fields, and the raw block for save reconstruction.
     *
     * @return array{
     *     apps: array<int, array{name: string, identity: array<string, string>, values: array<string, string>, raw: string}>,
     *     unreadable: bool,
     * }
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['apps' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['apps' => [], 'unreadable' => true];
        }

        $apps = [];
        foreach ($this->findExtAppBlocks($contents) as $block) {
            $name = $this->extractName($block);
            if ($name === null) {
                continue;
            }
            $values = array_map(fn (array $meta): string => (string) $meta['default'], self::PARAMS);
            foreach (self::PARAMS as $key => $meta) {
                if ($meta['type'] === 'lines') {
                    $lines = $this->extractRepeated($block, $key);
                    $values[$key] = implode("\n", $lines);
                } else {
                    $v = $this->extractScalar($block, $key);
                    if ($v !== null) {
                        $values[$key] = $v;
                    }
                }
            }

            $identity = [];
            foreach (self::IDENTITY_KEYS as $idKey) {
                $v = $this->extractScalar($block, $idKey);
                if ($v !== null) {
                    $identity[$idKey] = $v;
                }
            }

            $apps[] = [
                'name' => $name,
                'identity' => $identity,
                'values' => $values,
                'raw' => $block,
            ];
        }

        return ['apps' => $apps, 'unreadable' => false];
    }

    /**
     * Save edits back. `$updates` is keyed by app name → values array. Apps
     * not present in the update map are left unchanged. Identity fields
     * are never touched.
     *
     * @param  array<string, array<string, string>>  $updates
     *
     * @throws \RuntimeException on read/install/validate failure
     */
    public function save(Server $server, array $updates, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('ols-extapps', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        $newContents = $contents;
        $rewritten = 0;
        foreach ($this->findExtAppBlocks($contents) as $block) {
            $name = $this->extractName($block);
            if ($name === null || ! isset($updates[$name])) {
                continue;
            }
            $merged = $this->rewriteBlock($block, $updates[$name]);
            $newContents = str_replace($block, $merged, $newContents);
            $rewritten++;
            $emit->info('[ols-extapps] Rewriting extprocessor block: '.$name);
        }

        if ($rewritten === 0) {
            $emit->warn('No matching extprocessor blocks were rewritten.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'rewrite '.$rewritten.' extprocessor block(s)');
        $emit->success('OpenLiteSpeed reloaded with the new ExtApp config.');
    }

    /**
     * Common extprocessor types — kept here so the UI's type-select stays
     * in sync with what the service accepts. Operators can still hand-type
     * other values (servlet, logger, loadbalancer, etc.).
     *
     * @var array<string, string>
     */
    public const COMMON_TYPES = [
        'lsapi' => 'LSAPI (LiteSpeed-aware backends: PHP, etc.)',
        'fcgi' => 'FastCGI',
        'proxy' => 'HTTP proxy (upstream web server)',
    ];

    /**
     * Append a new extprocessor block to httpd_config.conf. Validates that
     * the name is unique, runs the same atomic-install + validate + reload
     * pipeline as save(), and rolls back on validation failure.
     *
     * @param  array{name: string, type: string, address: string, path?: string}  $identity
     * @param  array<string, mixed> $values  Tunables keyed by PARAMS keys; missing keys take defaults.
     *
     * @throws \RuntimeException
     */
    public function addApp(Server $server, array $identity, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $name = trim($identity['name'] ?? '');
        $type = trim($identity['type'] ?? '');
        $address = trim($identity['address'] ?? '');
        $path = trim((string) ($identity['path'] ?? ''));

        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if ($type === '') {
            throw new \RuntimeException('Type is required (e.g. lsapi, fcgi, proxy).');
        }
        if ($address === '') {
            throw new \RuntimeException('Address is required (e.g. uds://tmp/lshttpd/foo.sock or 127.0.0.1:9000).');
        }

        $emit->step('ols-extapps', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        // Block existing-name collision before writing anything — dply's
        // provisioner owns the lsphp* names; clobbering one is far worse
        // than failing the add.
        foreach ($this->findExtAppBlocks($contents) as $block) {
            if ($this->extractName($block) === $name) {
                throw new \RuntimeException("An extprocessor named `{$name}` already exists. Use a different name.");
            }
        }

        $block = $this->renderNewBlock($name, $type, $address, $path, $values);
        $newContents = rtrim($contents, "\n")."\n\n".$block."\n";

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'add extprocessor '.$name);
        $emit->success('ExtApp '.$name.' added.');
    }

    /**
     * Remove an extprocessor block by name. Wire-protected against
     * stripping the dply-managed lsphp* pools — those are owned by the
     * provisioner.
     *
     * @throws \RuntimeException
     */
    public function removeApp(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        if (preg_match('/^lsphp\d+$/', $name) === 1) {
            throw new \RuntimeException("`{$name}` is managed by dply (PHP backend). Use the PHP workspace to remove the version, not the ExtApps editor.");
        }

        $emit->step('ols-extapps', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        $found = false;
        $newContents = $contents;
        foreach ($this->findExtAppBlocks($contents) as $block) {
            if ($this->extractName($block) === $name) {
                // Strip the block plus the trailing blank line if it's there
                // so we don't accumulate empty paragraphs over time.
                $pattern = '/'.preg_quote($block, '/').'\R?\R?/';
                $newContents = preg_replace($pattern, '', $newContents, 1) ?? $newContents;
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("No extprocessor named `{$name}` found.");
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'remove extprocessor '.$name);
        $emit->success('ExtApp '.$name.' removed.');
    }

    /**
     * Shared remote pipeline: stage to /tmp → snapshot → install → validate
     * → reload. Used by save(), addApp(), removeApp() so the rollback
     * semantics are identical across all three.
     */
    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents, string $reason): void
    {
        $emit->step('ols-extapps', 'Staging new config to /tmp ('.$reason.')');
        $tmpRemote = '/tmp/dply-httpd_config.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new config on the server');
            throw new \RuntimeException('Failed to stage the new config on the server.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('ols-extapps', 'Snapshotting current config to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('ols-extapps', 'Installing new config at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous config left in place');
            throw new \RuntimeException('Failed to install the new config on the server.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('ols-extapps', 'Validating with `lshttpd -t`');
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
            $emit->step('ols-extapps', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous config restored.');
            throw new \RuntimeException('Config validation failed; previous config restored. lshttpd -t output:'."\n".trim($stripped));
        }
        $emit->success('Config validated.');

        $emit->step('ols-extapps', 'Reloading OpenLiteSpeed (`systemctl reload lshttpd`)');
        $reload = $ssh->exec('sudo -n systemctl reload lshttpd 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart lshttpd 2>&1', 30);
        }
    }

    /**
     * @param  array<string, mixed> $values
     */
    private function renderNewBlock(string $name, string $type, string $address, string $path, array $values): string
    {
        $body = ['extprocessor '.$name.' {'];
        $body[] = '  type                  '.$type;
        $body[] = '  address               '.$address;
        if ($path !== '') {
            $body[] = '  path                  '.$path;
        }
        foreach (self::PARAMS as $key => $meta) {
            $raw = $values[$key] ?? $meta['default'];
            if ($meta['type'] === 'lines') {
                foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $body[] = '  '.$key.'  '.$line;
                }
            } else {
                $body[] = '  '.$key.'  '.$this->normalizeScalar($meta['type'], $raw, $meta['default']);
            }
        }
        $body[] = '}';

        return implode("\n", $body);
    }

    /**
     * Find every `extprocessor <name> { ... }` block in the contents.
     *
     * @return list<string>
     */
    private function findExtAppBlocks(string $contents): array
    {
        if (preg_match_all('/^[\t ]*extprocessor\s+\S+\s*\{.*?^[\t ]*\}/sm', $contents, $m) === false) {
            return [];
        }

        return array_values($m[0] ?? []);
    }

    private function extractName(string $block): ?string
    {
        if (preg_match('/^[\t ]*extprocessor\s+(\S+)/m', $block, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function extractScalar(string $block, string $key): ?string
    {
        if (preg_match('/^[\t ]*'.preg_quote($key, '/').'\s+(\S.*?)\s*$/m', $block, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    /**
     * @return list<string>
     */
    private function extractRepeated(string $block, string $key): array
    {
        if (preg_match_all('/^[\t ]*'.preg_quote($key, '/').'\s+(\S.*?)\s*$/m', $block, $matches) === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $matches[1] ?? []), fn (string $v) => $v !== ''));
    }

    /**
     * Rewrite a block by replacing each known directive with the new value
     * (or adding it if missing). Unknown lines (comments, identity fields,
     * unrecognised directives) pass through unchanged so we don't strip
     * anything the provisioner cares about.
     *
     * @param  array<string, mixed> $values
     */
    private function rewriteBlock(string $block, array $values): string
    {
        // Pull out the opening / body / closing so we can rebuild cleanly.
        if (preg_match('/^([\t ]*)extprocessor\s+(\S+)\s*\{\R?(.*?)^([\t ]*)\}\s*$/sm', $block, $m) !== 1) {
            return $block;
        }
        $indent = $m[1];
        $name = $m[2];
        $body = $m[3];
        $closeIndent = $m[4];

        $bodyLines = preg_split('/\R/', rtrim($body, "\n")) ?: [];
        $managedKeys = array_keys(self::PARAMS);
        $emittedKeys = [];
        $newLines = [];

        foreach ($bodyLines as $line) {
            if (preg_match('/^([\t ]*)(\S+)(\s+)(.*)$/', $line, $lm) !== 1) {
                $newLines[] = $line;

                continue;
            }
            $lineIndent = $lm[1];
            $directive = $lm[2];

            if (! in_array($directive, $managedKeys, true)) {
                $newLines[] = $line;

                continue;
            }

            // Skip ALL existing occurrences of a managed directive — we
            // re-emit them once below to preserve declared order and avoid
            // duplicates if the file had both `persistConn 1` and a stray
            // second copy.
            if (isset($values[$directive])) {
                // Mark + emit on first hit so order is stable; subsequent
                // hits are dropped.
                if (! isset($emittedKeys[$directive])) {
                    foreach ($this->renderDirective($lineIndent, $directive, $values[$directive]) as $emitLine) {
                        $newLines[] = $emitLine;
                    }
                    $emittedKeys[$directive] = true;
                }
            } else {
                $newLines[] = $line;
            }
        }

        // Append directives that didn't exist in the original block.
        $bodyIndent = $indent.'  ';
        foreach ($values as $directive => $value) {
            if (! in_array($directive, $managedKeys, true)) {
                continue;
            }
            if (isset($emittedKeys[$directive])) {
                continue;
            }
            foreach ($this->renderDirective($bodyIndent, $directive, $value) as $emitLine) {
                $newLines[] = $emitLine;
            }
        }

        $newBody = implode("\n", $newLines);

        return $indent.'extprocessor '.$name." {\n".$newBody."\n".$closeIndent.'}';
    }

    /**
     * @return list<string>
     */
    private function renderDirective(string $indent, string $key, string $rawValue): array
    {
        $meta = self::PARAMS[$key];
        if ($meta['type'] === 'lines') {
            $lines = [];
            foreach (preg_split('/\R/', $rawValue) ?: [] as $v) {
                $v = trim($v);
                if ($v === '') {
                    continue;
                }
                $lines[] = $indent.$key.'  '.$v;
            }

            return $lines;
        }

        $value = $this->normalizeScalar($meta['type'], $rawValue, $meta['default']);

        return [$indent.$key.'  '.$value];
    }

    private function normalizeScalar(string $type, string $raw, string $default): string
    {
        $raw = trim($raw);
        if ($type === 'bool') {
            return in_array($raw, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
        }
        if ($type === 'int') {
            return preg_match('/^-?\d+$/', $raw) === 1 ? $raw : $default;
        }

        return $raw;
    }
}
