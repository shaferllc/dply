<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write HAProxy `backend <name>` blocks in
 * `/etc/haproxy/haproxy.cfg`. Companion to {@see HaproxyFrontendsConfig};
 * shares the same section parser and the same save pipeline (snapshot →
 * atomic install → `haproxy -c -f` → reload, with auto-revert).
 *
 * dply provisions a default `caddy_backends` pool listing each
 * per-site Caddy backend port; this editor lets operators add new
 * backends or tweak balance / health-check on existing ones.
 */
class HaproxyBackendsConfig
{
    private const REMOTE_PATH = '/etc/haproxy/haproxy.cfg';

    /**
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'mode' => [
            'type' => 'string',
            'default' => '',
            'label' => 'mode',
            'help' => '`http` (L7) or `tcp` (L4). Blank inherits from `defaults`.',
        ],
        'balance' => [
            'type' => 'string',
            'default' => 'roundrobin',
            'label' => 'balance',
            'help' => 'One of `roundrobin`, `leastconn`, `source`, `uri`, `static-rr`. `roundrobin` is the safe default.',
        ],
        'option httpchk' => [
            'type' => 'string',
            'default' => '',
            'label' => 'option httpchk',
            'help' => 'Health-check expression. e.g. `GET /` or `HEAD /health HTTP/1.1\\r\\nHost:\\ example.com`. Blank disables HTTP health checks.',
        ],
        'http-check expect' => [
            'type' => 'string',
            'default' => '',
            'label' => 'http-check expect',
            'help' => 'Expected response — e.g. `status 200` or `rstatus ^2`. Blank accepts any non-5xx as healthy.',
        ],
        'timeout connect' => [
            'type' => 'string',
            'default' => '',
            'label' => 'timeout connect (override)',
            'help' => 'Per-backend override of the `defaults` timeout connect. Blank inherits.',
        ],
        'timeout server' => [
            'type' => 'string',
            'default' => '',
            'label' => 'timeout server (override)',
            'help' => 'Per-backend override. Blank inherits.',
        ],
        'option forwardfor' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'option forwardfor',
            'help' => 'Add `X-Forwarded-For` if the frontend didn\'t. Usually set on the frontend instead.',
        ],
        'option httpclose' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'option httpclose',
            'help' => 'Force-close server connections after every response. Disables keep-alive on the backend side.',
        ],
    ];

    /**
     * @return array{backends: list<array{name: string, servers: list<string>, values: array<string, string>, raw: string}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['backends' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['backends' => [], 'unreadable' => true];
        }

        $out = [];
        foreach ($this->findBackendBlocks($contents) as $block) {
            $values = array_map(fn (array $m): string => (string) $m['default'], self::PARAMS);
            $servers = [];

            foreach (preg_split('/\R/', $block['body']) ?: [] as $line) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    continue;
                }
                if (preg_match('/^server\s+(\S+)\s+(.+)$/', $trim, $m) === 1) {
                    // Re-stitch `server <name> <addr> [opts]` into a single line.
                    $servers[] = $m[1].' '.trim($m[2]);

                    continue;
                }
                if (preg_match('/^mode\s+(\S+)/', $trim, $m) === 1) {
                    $values['mode'] = $m[1];

                    continue;
                }
                if (preg_match('/^balance\s+(\S.+)$/', $trim, $m) === 1) {
                    $values['balance'] = trim($m[1]);

                    continue;
                }
                if (preg_match('/^option\s+httpchk(?:\s+(.+))?$/', $trim, $m) === 1) {
                    $values['option httpchk'] = isset($m[1]) ? trim($m[1]) : '1';

                    continue;
                }
                if (preg_match('/^http-check\s+expect\s+(.+)$/', $trim, $m) === 1) {
                    $values['http-check expect'] = trim($m[1]);

                    continue;
                }
                if (preg_match('/^timeout\s+(connect|server)\s+(\S+)$/', $trim, $m) === 1) {
                    $values['timeout '.$m[1]] = $m[2];

                    continue;
                }
                if (preg_match('/^option\s+(forwardfor|httpclose)\b/', $trim, $m) === 1) {
                    $values['option '.$m[1]] = '1';
                }
            }

            $out[] = [
                'name' => $block['name'],
                'servers' => $servers,
                'values' => $values,
                'raw' => $block['raw'],
            ];
        }

        return ['backends' => $out, 'unreadable' => false];
    }

    /**
     * @param  array<string, array{servers: list<string>, values: array<string, string>}>  $updates
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, array $updates, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        if ($updates === []) {
            $emit->warn('No backend updates supplied.');

            return;
        }

        $emit->step('haproxy-backends', 'Reading current haproxy.cfg');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read haproxy.cfg from the server.');
        }

        $newContents = $contents;
        $rewritten = 0;
        foreach ($this->findBackendBlocks($contents) as $block) {
            if (! array_key_exists($block['name'], $updates)) {
                continue;
            }
            $payload = $updates[$block['name']];
            $rendered = $this->renderBackend(
                $block['name'],
                array_values((array) ($payload['servers'] ?? [])),
                (array) ($payload['values'] ?? []),
            );
            $newContents = str_replace($block['raw'], $rendered, $newContents);
            $rewritten++;
            $emit->info('[haproxy-backends] Rewriting backend: '.$block['name']);
        }

        if ($rewritten === 0) {
            $emit->warn('No matching backend blocks were rewritten.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'rewrite '.$rewritten.' backend(s)');
        $emit->success('HAProxy reloaded with the updated backends.');
    }

    /**
     * @param  list<string>  $servers
     * @param  array<string, string>  $values
     *
     * @throws \RuntimeException
     */
    public function addBackend(Server $server, string $name, array $servers, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        $servers = array_values(array_filter(array_map('trim', $servers), fn (string $s) => $s !== ''));
        if ($servers === []) {
            throw new \RuntimeException('At least one `server` line is required (e.g. `app1 127.0.0.1:8080 check`).');
        }

        $emit->step('haproxy-backends', 'Reading current haproxy.cfg');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read haproxy.cfg from the server.');
        }

        foreach ($this->findBackendBlocks($contents) as $block) {
            if ($block['name'] === $name) {
                throw new \RuntimeException("A backend named `{$name}` already exists. Use a different name.");
            }
        }

        $rendered = $this->renderBackend($name, $servers, $values);
        $newContents = rtrim($contents, "\n")."\n\n".$rendered."\n";

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'add backend '.$name);
        $emit->success('Backend '.$name.' added.');
    }

    /**
     * @throws \RuntimeException
     */
    public function removeBackend(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('haproxy-backends', 'Reading current haproxy.cfg');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read haproxy.cfg from the server.');
        }

        $found = false;
        $newContents = $contents;
        foreach ($this->findBackendBlocks($contents) as $block) {
            if ($block['name'] === $name) {
                $pattern = '/'.preg_quote($block['raw'], '/').'\R?\R?/';
                $newContents = preg_replace($pattern, '', $newContents, 1) ?? $newContents;
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("No backend named `{$name}` found.");
        }

        // Warn if any frontend still routes to this backend.
        if (preg_match('/^[\t ]+(default_backend|use_backend)\s+'.preg_quote($name, '/').'\b/m', $newContents) === 1) {
            $emit->warn('A frontend still references `'.$name.'` via default_backend or use_backend — `haproxy -c -f` will fail.');
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'remove backend '.$name);
        $emit->success('Backend '.$name.' removed.');
    }

    /**
     * @param  list<string>  $servers
     * @param  array<string, string>  $values
     */
    private function renderBackend(string $name, array $servers, array $values): string
    {
        $lines = ['backend '.$name];
        $mode = trim((string) ($values['mode'] ?? ''));
        if ($mode !== '') {
            $lines[] = "\tmode ".$mode;
        }
        $balance = trim((string) ($values['balance'] ?? ''));
        if ($balance !== '') {
            $lines[] = "\tbalance ".$balance;
        }
        $httpchk = trim((string) ($values['option httpchk'] ?? ''));
        if ($httpchk === '1') {
            $lines[] = "\toption httpchk";
        } elseif ($httpchk !== '') {
            $lines[] = "\toption httpchk ".$httpchk;
        }
        $expect = trim((string) ($values['http-check expect'] ?? ''));
        if ($expect !== '') {
            $lines[] = "\thttp-check expect ".$expect;
        }
        foreach (['connect', 'server'] as $t) {
            $tv = trim((string) ($values['timeout '.$t] ?? ''));
            if ($tv !== '') {
                $lines[] = "\ttimeout ".$t.' '.$tv;
            }
        }
        foreach (['forwardfor', 'httpclose'] as $opt) {
            if (($values['option '.$opt] ?? '0') === '1') {
                $lines[] = "\toption ".$opt;
            }
        }
        foreach ($servers as $srv) {
            $srv = trim((string) $srv);
            if ($srv !== '') {
                $lines[] = "\tserver ".$srv;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, body: string, raw: string}>
     */
    private function findBackendBlocks(string $contents): array
    {
        $out = [];
        if (preg_match_all('/^backend\s+(\S+)/m', $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        foreach ($matches[0] ?? [] as $i => $headerMatch) {
            $name = $matches[1][$i][0];
            $headerStart = $headerMatch[1];
            $bodyStart = $this->findLineEnd($contents, $headerStart + strlen($headerMatch[0]));
            $bodyEnd = $this->findNextSectionStart($contents, $bodyStart);
            $body = substr($contents, $bodyStart, $bodyEnd - $bodyStart);
            $raw = rtrim(substr($contents, $headerStart, $bodyEnd - $headerStart), "\n");
            $out[] = ['name' => $name, 'body' => $body, 'raw' => $raw];
        }

        return $out;
    }

    private function findLineEnd(string $contents, int $offset): int
    {
        $eol = strpos($contents, "\n", $offset);

        return $eol === false ? strlen($contents) : $eol + 1;
    }

    private function findNextSectionStart(string $contents, int $offset): int
    {
        $pattern = '/^(global|defaults|frontend|backend|listen|cache|peers|resolvers|userlist|http-errors|ring|program)\b/m';
        if (preg_match($pattern, $contents, $next, PREG_OFFSET_CAPTURE, $offset) === 1) {
            return $next[0][1];
        }

        return strlen($contents);
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents, string $reason): void
    {
        $emit->step('haproxy-backends', 'Staging new haproxy.cfg to /tmp ('.$reason.')');
        $tmpRemote = '/tmp/dply-haproxy.cfg.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new haproxy.cfg');
            throw new \RuntimeException('Failed to stage the new haproxy.cfg.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('haproxy-backends', 'Snapshotting current haproxy.cfg to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('haproxy-backends', 'Installing new haproxy.cfg at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous haproxy.cfg left in place');
            throw new \RuntimeException('Failed to install the new haproxy.cfg.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('haproxy-backends', 'Validating with `haproxy -c -f`');
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
            $emit->step('haproxy-backends', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous haproxy.cfg restored.');
            throw new \RuntimeException('Config validation failed; previous haproxy.cfg restored. haproxy -c output:'."\n".trim($stripped));
        }
        $emit->success('haproxy.cfg validated.');

        $emit->step('haproxy-backends', 'Reloading HAProxy');
        $reload = $ssh->exec('sudo -n systemctl reload haproxy 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart haproxy 2>&1', 30);
        }
    }
}
