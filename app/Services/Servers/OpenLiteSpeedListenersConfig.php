<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write OpenLiteSpeed's `listener <name> { ... }` blocks in
 * `/usr/local/lsws/conf/httpd_config.conf`. Each listener binds an
 * address (host:port) and optionally terminates TLS for the vhosts
 * mapped to it.
 *
 * dply emits a `listener Default` block on :80 during provisioning;
 * remove() refuses to delete that one (and any other dply-managed name)
 * because the switch flow re-renders it from the config builder on
 * reconcile and an out-of-band delete would re-appear on next switch.
 *
 * The `map` directive (repeated, one per vhost↔domain mapping) is
 * surfaced read-only — those are owned by the per-site provisioner.
 *
 * Save/add/remove all snapshot → atomic install → `lshttpd -t` →
 * reload, with rollback from the .dply-bak.<ts> snapshot on validation
 * failure. Mirrors {@see OpenLiteSpeedExtAppsConfig}.
 */
class OpenLiteSpeedListenersConfig
{
    private const REMOTE_PATH = '/usr/local/lsws/conf/httpd_config.conf';

    /**
     * Listener names dply's provisioner / switch flow owns. Removing one
     * just means it'll be re-emitted on the next reconcile, and any port
     * that depended on it goes dark in between — so we hard-block the
     * delete and tell the operator to use the right workflow.
     *
     * @var list<string>
     */
    public const MANAGED_NAMES = ['Default'];

    /**
     * Identity directives — fixed when the listener is created. address +
     * secure changes are destructive (re-bind the port) and TLS material
     * is best edited via the create form so the cert paths land before
     * `secure 1` flips, so we keep them out of the live edit form.
     *
     * @var list<string>
     */
    public const IDENTITY_KEYS = ['address', 'secure'];

    /**
     * Editable directives → metadata for the UI.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'sslProtocol' => [
            'type' => 'int',
            'default' => '24',
            'label' => 'TLS protocol bitmask',
            'help' => 'Bitmask of allowed TLS versions. 24 = TLS 1.2 + 1.3 (modern). 30 also enables TLS 1.0/1.1 (legacy).',
        ],
        'enableSpdy' => [
            'type' => 'int',
            'default' => '15',
            'label' => 'HTTP/2 + HTTP/3 bitmask',
            'help' => '0=off, 1=SPDY/2, 3=+SPDY/3, 7=+HTTP/2, 15=+HTTP/3. Most servers want 15 (everything).',
        ],
        'enableQuic' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Enable QUIC (HTTP/3)',
            'help' => 'UDP-based HTTP/3 transport. Requires the listener to also be listening on UDP and a firewall rule.',
        ],
        'enableStapling' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'OCSP stapling',
            'help' => 'Cache and serve the CA\'s OCSP response with the TLS handshake so the client doesn\'t need to query the CA.',
        ],
        'clientVerify' => [
            'type' => 'int',
            'default' => '0',
            'label' => 'mTLS client verify',
            'help' => '0=off, 1=optional, 2=require, 3=optional-no-CA. Set to 2 to require a client cert (mTLS).',
        ],
        'binding' => [
            'type' => 'string',
            'default' => '',
            'label' => 'CPU binding bitmask',
            'help' => 'Comma-separated CPU IDs the listener\'s workers may run on. Blank = all cores.',
        ],
        'keyFile' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Private key path',
            'help' => 'Path to the TLS private key on the server. Required when secure=1.',
        ],
        'certFile' => [
            'type' => 'string',
            'default' => '',
            'label' => 'Certificate path',
            'help' => 'Path to the TLS cert (or full chain) on the server. Required when secure=1.',
        ],
        'certChain' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'Certificate is a chain',
            'help' => 'Set when certFile contains the full chain (cert + intermediates concatenated).',
        ],
    ];

    /**
     * @return array{
     *     listeners: array<int, array{name: string, identity: array<string, string>, values: array<string, string>, maps: list<string>, raw: string}>,
     *     unreadable: bool,
     * }
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['listeners' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['listeners' => [], 'unreadable' => true];
        }

        $listeners = [];
        foreach ($this->findListenerBlocks($contents) as $block) {
            $name = $this->extractName($block);
            if ($name === null) {
                continue;
            }
            $values = array_map(fn (array $meta): string => (string) $meta['default'], self::PARAMS);
            foreach (self::PARAMS as $key => $meta) {
                $v = $this->extractScalar($block, $key);
                if ($v !== null) {
                    $values[$key] = $v;
                }
            }
            $identity = [];
            foreach (self::IDENTITY_KEYS as $idKey) {
                $v = $this->extractScalar($block, $idKey);
                if ($v !== null) {
                    $identity[$idKey] = $v;
                }
            }

            $listeners[] = [
                'name' => $name,
                'identity' => $identity,
                'values' => $values,
                'maps' => $this->extractRepeated($block, 'map'),
                'raw' => $block,
            ];
        }

        return ['listeners' => $listeners, 'unreadable' => false];
    }

    /**
     * @param  array<string, array<string, string>>  $updates  Listener-name → values
     * @throws \RuntimeException
     */
    public function save(Server $server, array $updates, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('ols-listeners', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        $newContents = $contents;
        $rewritten = 0;
        foreach ($this->findListenerBlocks($contents) as $block) {
            $name = $this->extractName($block);
            if ($name === null || ! isset($updates[$name])) {
                continue;
            }
            $merged = $this->rewriteBlock($block, $updates[$name]);
            $newContents = str_replace($block, $merged, $newContents);
            $rewritten++;
            $emit->info('[ols-listeners] Rewriting listener block: '.$name);
        }

        if ($rewritten === 0) {
            $emit->warn('No matching listener blocks were rewritten.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'rewrite '.$rewritten.' listener block(s)');
        $emit->success('OpenLiteSpeed reloaded with the new listener config.');
    }

    /**
     * @param  array{name: string, address: string, secure: string, keyFile?: string, certFile?: string}  $identity
     * @param  array<string, string>  $values
     * @throws \RuntimeException
     */
    public function addListener(Server $server, array $identity, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $name = trim($identity['name'] ?? '');
        $address = trim($identity['address'] ?? '');
        $secure = trim((string) ($identity['secure'] ?? '0'));
        $secure = in_array($secure, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';

        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        if ($address === '') {
            throw new \RuntimeException('Address is required (e.g. `*:443`, `127.0.0.1:8080`).');
        }

        $emit->step('ols-listeners', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        foreach ($this->findListenerBlocks($contents) as $block) {
            if ($this->extractName($block) === $name) {
                throw new \RuntimeException("A listener named `{$name}` already exists. Use a different name.");
            }
        }

        // Identity-supplied TLS material (only relevant when secure=1) folds
        // into the directive set so renderNewBlock emits the same shape as
        // an editor edit would.
        if ($secure === '1') {
            foreach (['keyFile', 'certFile'] as $tlsKey) {
                if (! empty($identity[$tlsKey])) {
                    $values[$tlsKey] = (string) $identity[$tlsKey];
                }
            }
        }

        $block = $this->renderNewBlock($name, $address, $secure, $values);
        $newContents = rtrim($contents, "\n")."\n\n".$block."\n";

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'add listener '.$name);
        $emit->success('Listener '.$name.' added.');
    }

    public function removeListener(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        if (in_array($name, self::MANAGED_NAMES, true)) {
            throw new \RuntimeException("`{$name}` is managed by dply. The switch / provisioner re-emits it on reconcile, so removing it here would only flap the port.");
        }

        $emit->step('ols-listeners', 'Reading current httpd_config.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read httpd_config.conf from the server.');
        }

        $found = false;
        $newContents = $contents;
        foreach ($this->findListenerBlocks($contents) as $block) {
            if ($this->extractName($block) === $name) {
                $pattern = '/'.preg_quote($block, '/').'\R?\R?/';
                $newContents = preg_replace($pattern, '', $newContents, 1) ?? $newContents;
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("No listener named `{$name}` found.");
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'remove listener '.$name);
        $emit->success('Listener '.$name.' removed.');
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents, string $reason): void
    {
        $emit->step('ols-listeners', 'Staging new config to /tmp ('.$reason.')');
        $tmpRemote = '/tmp/dply-httpd_config.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new config on the server');
            throw new \RuntimeException('Failed to stage the new config on the server.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('ols-listeners', 'Snapshotting current config to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('ols-listeners', 'Installing new config at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous config left in place');
            throw new \RuntimeException('Failed to install the new config on the server.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('ols-listeners', 'Validating with `lshttpd -t`');
        $validate = $ssh->exec('sudo -n /usr/local/lsws/bin/lshttpd -t 2>&1; echo "__exit__:$?"', 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? \App\Models\ConsoleAction::LEVEL_WARN : \App\Models\ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->step('ols-listeners', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous config restored.');
            throw new \RuntimeException('Config validation failed; previous config restored. lshttpd -t output:'."\n".trim($stripped));
        }
        $emit->success('Config validated.');

        $emit->step('ols-listeners', 'Reloading OpenLiteSpeed (`systemctl reload lshttpd`)');
        $reload = $ssh->exec('sudo -n systemctl reload lshttpd 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart lshttpd 2>&1', 30);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function renderNewBlock(string $name, string $address, string $secure, array $values): string
    {
        $body = ['listener '.$name.' {'];
        $body[] = '  address               '.$address;
        $body[] = '  secure                '.$secure;
        foreach (self::PARAMS as $key => $meta) {
            $raw = $values[$key] ?? $meta['default'];
            // Skip empty strings on optional text params so we don't write
            // `binding ""` lines OLS would refuse to parse.
            if ($meta['type'] === 'string' && trim((string) $raw) === '') {
                continue;
            }
            // TLS material is only meaningful on secure=1 listeners.
            if (in_array($key, ['keyFile', 'certFile', 'certChain', 'sslProtocol', 'enableSpdy', 'enableQuic', 'enableStapling', 'clientVerify'], true) && $secure !== '1') {
                continue;
            }
            $body[] = '  '.$key.'  '.$this->normalizeScalar($meta['type'], $raw, $meta['default']);
        }
        $body[] = '}';

        return implode("\n", $body);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function rewriteBlock(string $block, array $values): string
    {
        if (preg_match('/^([\t ]*)listener\s+(\S+)\s*\{\R?(.*?)^([\t ]*)\}\s*$/sm', $block, $m) !== 1) {
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
            if (isset($values[$directive])) {
                if (! isset($emittedKeys[$directive])) {
                    $rendered = $this->renderDirective($lineIndent, $directive, $values[$directive]);
                    foreach ($rendered as $emitLine) {
                        $newLines[] = $emitLine;
                    }
                    $emittedKeys[$directive] = true;
                }
            } else {
                $newLines[] = $line;
            }
        }

        $bodyIndent = $indent.'  ';
        foreach ($values as $directive => $value) {
            if (! in_array($directive, $managedKeys, true)) {
                continue;
            }
            if (isset($emittedKeys[$directive])) {
                continue;
            }
            $rendered = $this->renderDirective($bodyIndent, $directive, $value);
            foreach ($rendered as $emitLine) {
                $newLines[] = $emitLine;
            }
        }

        $newBody = implode("\n", $newLines);

        return $indent.'listener '.$name." {\n".$newBody."\n".$closeIndent.'}';
    }

    /**
     * @return list<string>
     */
    private function renderDirective(string $indent, string $key, string $rawValue): array
    {
        $meta = self::PARAMS[$key];
        $value = $this->normalizeScalar($meta['type'], $rawValue, $meta['default']);

        // Don't render blank string params (e.g. `binding`) — emitting an
        // empty value flips OLS into a parse error.
        if ($meta['type'] === 'string' && trim($value) === '') {
            return [];
        }

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

    /**
     * @return list<string>
     */
    private function findListenerBlocks(string $contents): array
    {
        if (preg_match_all('/^[\t ]*listener\s+\S+\s*\{.*?^[\t ]*\}/sm', $contents, $m) === false) {
            return [];
        }

        return array_values($m[0] ?? []);
    }

    private function extractName(string $block): ?string
    {
        if (preg_match('/^[\t ]*listener\s+(\S+)/m', $block, $m) !== 1) {
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
}
