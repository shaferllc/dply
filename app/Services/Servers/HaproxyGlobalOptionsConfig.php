<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write HAProxy's `global` and `defaults` sections in
 * `/etc/haproxy/haproxy.cfg`. Section-based config (not brace-delimited):
 * each `global` / `defaults` / `frontend <name>` / `backend <name>` block
 * starts at column 0 with the keyword and runs until the next column-0
 * keyword or EOF.
 *
 * Frontend / backend / listen / cache blocks pass through byte-for-byte
 * (dply's edge-proxy job rewrites those when frontends/backends change).
 *
 * Save pipeline: snapshot → atomic install → `haproxy -c -f` →
 * `systemctl reload haproxy` with auto-revert from .dply-bak.<ts> on
 * validation failure.
 */
class HaproxyGlobalOptionsConfig
{
    private const REMOTE_PATH = '/etc/haproxy/haproxy.cfg';

    /**
     * `global` section tunables.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const GLOBAL_PARAMS = [
        'maxconn' => [
            'type' => 'int',
            'default' => '50000',
            'label' => 'maxconn',
            'help' => 'Hard cap on total simultaneous connections across all frontends. 50k is a sane default; bump for high-traffic.',
        ],
        'nbthread' => [
            'type' => 'int',
            'default' => '',
            'label' => 'nbthread',
            'help' => 'Threads per process. Blank = HAProxy default (auto-detect from cpu-map). Set explicitly only if pinning.',
        ],
        'daemon' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'daemon',
            'help' => 'Run in background. Almost always on except for testing under a foreground init.',
        ],
        'log' => [
            'type' => 'string',
            'default' => '127.0.0.1 local0',
            'label' => 'log',
            'help' => 'Syslog destination. e.g. `127.0.0.1 local0` for the local syslog, `/dev/log local0` for journald.',
        ],
        'user' => [
            'type' => 'string',
            'default' => 'haproxy',
            'label' => 'user',
            'help' => 'Unix user HAProxy drops privileges to after binding sockets.',
        ],
        'group' => [
            'type' => 'string',
            'default' => 'haproxy',
            'label' => 'group',
            'help' => 'Unix group, same logic as user.',
        ],
        'ssl-default-bind-options' => [
            'type' => 'string',
            'default' => 'ssl-min-ver TLSv1.2 no-tls-tickets',
            'label' => 'ssl-default-bind-options',
            'help' => 'TLS bind defaults — minimum protocol, session ticket policy, etc. `no-tls-tickets` improves forward secrecy.',
        ],
        'ssl-default-bind-ciphers' => [
            'type' => 'string',
            'default' => '',
            'label' => 'ssl-default-bind-ciphers',
            'help' => 'Cipher list (OpenSSL syntax). Blank = HAProxy compiled default (modern).',
        ],
    ];

    /**
     * `defaults` section tunables — these apply to every frontend / backend
     * unless overridden in the block itself.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const DEFAULTS_PARAMS = [
        'mode' => [
            'type' => 'string',
            'default' => 'http',
            'label' => 'mode',
            'help' => '`http` (L7) or `tcp` (L4). dply\'s edge-proxy setup uses http so HAProxy can route on Host: headers.',
        ],
        'timeout connect' => [
            'type' => 'string',
            'default' => '5s',
            'label' => 'timeout connect',
            'help' => 'Max time HAProxy waits when connecting to a backend. 5-10s typical.',
        ],
        'timeout client' => [
            'type' => 'string',
            'default' => '30s',
            'label' => 'timeout client',
            'help' => 'Max idle time on the client side. Bump for long-polling / SSE / WebSocket.',
        ],
        'timeout server' => [
            'type' => 'string',
            'default' => '30s',
            'label' => 'timeout server',
            'help' => 'Max idle time on the backend side. Same shape as timeout client.',
        ],
        'timeout http-request' => [
            'type' => 'string',
            'default' => '10s',
            'label' => 'timeout http-request',
            'help' => 'Max time to receive a complete HTTP request from the client. Mitigates slowloris.',
        ],
        'timeout http-keep-alive' => [
            'type' => 'string',
            'default' => '15s',
            'label' => 'timeout http-keep-alive',
            'help' => 'How long an idle keep-alive client connection stays open.',
        ],
        'option httplog' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'option httplog',
            'help' => 'Log every request with HTTP details. Off only for noisy / privacy-sensitive setups.',
        ],
        'option dontlognull' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'option dontlognull',
            'help' => 'Skip log lines for connections that closed without any request (TCP scanners, health checks).',
        ],
        'option forwardfor' => [
            'type' => 'bool',
            'default' => '1',
            'label' => 'option forwardfor',
            'help' => 'Add `X-Forwarded-For: <client-ip>` so backends see the original client.',
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

        $globalBody = $this->extractSectionBody($contents, 'global');
        if ($globalBody !== null) {
            foreach (self::GLOBAL_PARAMS as $key => $meta) {
                $v = $this->extractDirective($globalBody, $key, $meta['type']);
                if ($v !== null) {
                    $values['global_'.$this->slug($key)] = $v;
                }
            }
        }

        $defaultsBody = $this->extractSectionBody($contents, 'defaults');
        if ($defaultsBody !== null) {
            foreach (self::DEFAULTS_PARAMS as $key => $meta) {
                $v = $this->extractDirective($defaultsBody, $key, $meta['type']);
                if ($v !== null) {
                    $values['defaults_'.$this->slug($key)] = $v;
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

        $emit->step('haproxy-globals', 'Reading current haproxy.cfg');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read haproxy.cfg from the server.');
        }

        $newContents = $this->upsertSection($contents, 'global', self::GLOBAL_PARAMS, $values, 'global_');
        $newContents = $this->upsertSection($newContents, 'defaults', self::DEFAULTS_PARAMS, $values, 'defaults_');

        if ($newContents === $contents) {
            $emit->info('No changes to haproxy.cfg.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents);
        $emit->success('HAProxy reloaded with the new global options.');
    }

    /**
     * @param  array<string, array{type: string, default: string, label: string, help: string}>  $params
     * @param  array<string, string>  $values
     */
    private function upsertSection(string $contents, string $sectionName, array $params, array $values, string $keyPrefix): string
    {
        $sectionMatch = $this->findSection($contents, $sectionName);
        if ($sectionMatch === null) {
            // Section doesn't exist — append a fresh one.
            $lines = [$sectionName];
            foreach ($params as $key => $meta) {
                $val = trim((string) ($values[$keyPrefix.$this->slug($key)] ?? ''));
                if ($val === '' || ($meta['type'] === 'bool' && $val !== '1')) {
                    continue;
                }
                $lines[] = $this->renderDirective($key, $meta['type'], $val);
            }
            if (count($lines) <= 1) {
                return $contents;
            }

            return rtrim($contents, "\n")."\n\n".implode("\n", $lines)."\n";
        }

        [$bodyStart, $bodyEnd] = $sectionMatch;
        $body = substr($contents, $bodyStart, $bodyEnd - $bodyStart);
        $newBody = $body;

        foreach ($params as $key => $meta) {
            $formKey = $keyPrefix.$this->slug($key);
            if (! array_key_exists($formKey, $values)) {
                continue;
            }
            $raw = trim((string) $values[$formKey]);

            // bool=0 → remove the directive (HAProxy options use the bare
            // `option <name>` form; "off" isn't legal for most of them).
            // Same for boolean keywords like `daemon`.
            if ($meta['type'] === 'bool' && $raw !== '1') {
                $newBody = (string) preg_replace($this->directivePattern($key), '', $newBody);

                continue;
            }
            // Blank optional strings — skip without touching.
            if ($meta['type'] === 'string' && $raw === '' && $meta['default'] === '') {
                continue;
            }

            $rendered = $this->renderDirective($key, $meta['type'], $raw);
            if (preg_match($this->directivePattern($key), $newBody) === 1) {
                $newBody = (string) preg_replace($this->directivePattern($key), $rendered, $newBody, 1);
            } else {
                $newBody = rtrim($newBody, "\n")."\n".$rendered."\n";
            }
        }

        return substr($contents, 0, $bodyStart).$newBody.substr($contents, $bodyEnd);
    }

    private function directivePattern(string $key): string
    {
        // Multi-word keys (`timeout connect`, `option httplog`) collapse to
        // a directive whose first token is the head and the rest is part of
        // the directive name. We match the whole prefix at line start.
        return '/^[\t ]+'.preg_quote($key, '/').'(?:\s+[^\n]*)?\R?/m';
    }

    private function renderDirective(string $key, string $type, string $value): string
    {
        if ($type === 'bool') {
            return "\t".$key;
        }

        return "\t".$key.' '.$value;
    }

    private function extractDirective(string $body, string $key, string $type): ?string
    {
        $pattern = '/^[\t ]+'.preg_quote($key, '/').'(?:\s+([^\n]+))?$/m';
        if (preg_match($pattern, $body, $m) !== 1) {
            return null;
        }
        if ($type === 'bool') {
            return '1';
        }

        return isset($m[1]) ? trim($m[1]) : '';
    }

    /**
     * Find a section (`global`, `defaults`, `frontend foo`, …). Returns
     * the byte offset of the BODY start (first char after the section
     * header line) and the body END (first byte of the next section
     * header, or strlen if EOF).
     *
     * @return array{0: int, 1: int}|null
     */
    private function findSection(string $contents, string $sectionName): ?array
    {
        $headerPattern = '/^'.preg_quote($sectionName, '/').'\b[^\n]*\R/m';
        if (preg_match($headerPattern, $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $bodyStart = $m[0][1] + strlen($m[0][0]);

        // Next section starts at column 0 with one of the known keywords.
        $nextPattern = '/^(global|defaults|frontend|backend|listen|cache|peers|resolvers|userlist|http-errors|ring|program)\b/m';
        if (preg_match($nextPattern, $contents, $next, PREG_OFFSET_CAPTURE, $bodyStart) === 1) {
            return [$bodyStart, $next[0][1]];
        }

        return [$bodyStart, strlen($contents)];
    }

    private function extractSectionBody(string $contents, string $sectionName): ?string
    {
        $match = $this->findSection($contents, $sectionName);
        if ($match === null) {
            return null;
        }
        [$start, $end] = $match;

        return substr($contents, $start, $end - $start);
    }

    /**
     * Convert "timeout connect" / "option httplog" / "ssl-default-bind-options"
     * into form-key-safe slugs (Livewire treats dots as nested access).
     */
    private function slug(string $key): string
    {
        return str_replace([' ', '-'], '_', $key);
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $out = [];
        foreach (self::GLOBAL_PARAMS as $key => $meta) {
            $out['global_'.$this->slug($key)] = (string) $meta['default'];
        }
        foreach (self::DEFAULTS_PARAMS as $key => $meta) {
            $out['defaults_'.$this->slug($key)] = (string) $meta['default'];
        }

        return $out;
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents): void
    {
        $emit->step('haproxy-globals', 'Staging new haproxy.cfg to /tmp');
        $tmpRemote = '/tmp/dply-haproxy.cfg.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new haproxy.cfg');
            throw new \RuntimeException('Failed to stage the new haproxy.cfg.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('haproxy-globals', 'Snapshotting current haproxy.cfg to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('haproxy-globals', 'Installing new haproxy.cfg at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous haproxy.cfg left in place');
            throw new \RuntimeException('Failed to install the new haproxy.cfg.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('haproxy-globals', 'Validating with `haproxy -c -f`');
        $validate = $ssh->exec('sudo -n haproxy -c -f '.escapeshellarg(self::REMOTE_PATH).' 2>&1; echo "__exit__:$?"', 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? ConsoleAction::LEVEL_WARN : ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->step('haproxy-globals', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous haproxy.cfg restored.');
            throw new \RuntimeException('Config validation failed; previous haproxy.cfg restored. haproxy -c output:'."\n".trim($stripped));
        }
        $emit->success('haproxy.cfg validated.');

        $emit->step('haproxy-globals', 'Reloading HAProxy (`systemctl reload haproxy`)');
        $reload = $ssh->exec('sudo -n systemctl reload haproxy 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart haproxy 2>&1', 30);
        }
    }
}
