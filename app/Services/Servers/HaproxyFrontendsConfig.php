<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write HAProxy `frontend <name>` blocks in
 * `/etc/haproxy/haproxy.cfg`. Section-based parsing same as
 * {@see HaproxyGlobalOptionsConfig} — each block runs from its
 * column-0 keyword to the next column-0 keyword (or EOF).
 *
 * dply's edge-proxy job provisions a default frontend listening on :80
 * that routes to the Caddy-backend pool. This editor lets operators
 * tweak the existing frontend or add new ones (e.g. an mTLS frontend
 * on an alt port, a stats listener, a frontend for a separate
 * subdomain).
 *
 * Save / add / remove all go through:
 *   snapshot → atomic install → `haproxy -c -f` → reload
 * with auto-revert from .dply-bak.<ts> on validation failure.
 */
class HaproxyFrontendsConfig
{
    private const REMOTE_PATH = '/etc/haproxy/haproxy.cfg';

    /**
     * Per-frontend tunables.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const PARAMS = [
        'mode' => [
            'type' => 'string',
            'default' => '',
            'label' => 'mode',
            'help' => '`http` (L7) or `tcp` (L4). Blank inherits from `defaults`.',
        ],
        'default_backend' => [
            'type' => 'string',
            'default' => '',
            'label' => 'default_backend',
            'help' => 'Name of the `backend <name>` block this frontend routes to when no ACL matches.',
        ],
        'maxconn' => [
            'type' => 'int',
            'default' => '',
            'label' => 'maxconn',
            'help' => 'Per-frontend connection cap. Blank inherits from `global maxconn`.',
        ],
        'option httplog' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'option httplog',
            'help' => 'Log every request with HTTP details. Off here will inherit whatever `defaults` says.',
        ],
        'option dontlognull' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'option dontlognull',
            'help' => 'Skip log lines for connections that closed without a request.',
        ],
        'option forwardfor' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'option forwardfor',
            'help' => 'Add `X-Forwarded-For: <client-ip>` so the backend sees the original client.',
        ],
    ];

    /**
     * @return array{frontends: list<array{name: string, binds: list<string>, values: array<string, string>, raw: string}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['frontends' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['frontends' => [], 'unreadable' => true];
        }

        $out = [];
        foreach ($this->findFrontendBlocks($contents) as $block) {
            $values = array_map(fn (array $m): string => (string) $m['default'], self::PARAMS);
            $binds = [];

            foreach (preg_split('/\R/', $block['body']) ?: [] as $line) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    continue;
                }
                if (preg_match('/^bind\s+(.+)$/', $trim, $m) === 1) {
                    $binds[] = trim($m[1]);

                    continue;
                }
                if (preg_match('/^mode\s+(\S+)/', $trim, $m) === 1) {
                    $values['mode'] = $m[1];

                    continue;
                }
                if (preg_match('/^default_backend\s+(\S+)/', $trim, $m) === 1) {
                    $values['default_backend'] = $m[1];

                    continue;
                }
                if (preg_match('/^maxconn\s+(\d+)/', $trim, $m) === 1) {
                    $values['maxconn'] = $m[1];

                    continue;
                }
                if (preg_match('/^option\s+(httplog|dontlognull|forwardfor)\b/', $trim, $m) === 1) {
                    $values['option '.$m[1]] = '1';
                }
            }

            $out[] = [
                'name' => $block['name'],
                'binds' => $binds,
                'values' => $values,
                'raw' => $block['raw'],
            ];
        }

        return ['frontends' => $out, 'unreadable' => false];
    }

    /**
     * Save edits across frontend blocks. `$updates` is keyed by frontend name →
     * ['binds' => list<string>, 'values' => array<string, string>]. Frontends
     * not in $updates pass through untouched.
     *
     * @param  array<string, array{binds: list<string>, values: array<string, string>}>  $updates
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, array $updates, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        if ($updates === []) {
            $emit->warn('No frontend updates supplied.');

            return;
        }

        $emit->step('haproxy-frontends', 'Reading current haproxy.cfg');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read haproxy.cfg from the server.');
        }

        $newContents = $contents;
        $rewritten = 0;
        foreach ($this->findFrontendBlocks($contents) as $block) {
            if (! array_key_exists($block['name'], $updates)) {
                continue;
            }
            $payload = $updates[$block['name']];
            $rendered = $this->renderFrontend(
                $block['name'],
                array_values((array) ($payload['binds'] ?? [])),
                (array) ($payload['values'] ?? []),
            );
            $newContents = str_replace($block['raw'], $rendered, $newContents);
            $rewritten++;
            $emit->info('[haproxy-frontends] Rewriting frontend: '.$block['name']);
        }

        if ($rewritten === 0) {
            $emit->warn('No matching frontend blocks were rewritten.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'rewrite '.$rewritten.' frontend(s)');
        $emit->success('HAProxy reloaded with the updated frontends.');
    }

    /**
     * @param  list<string>  $binds
     * @param  array<string, string>  $values
     *
     * @throws \RuntimeException
     */
    public function addFrontend(Server $server, string $name, array $binds, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        $binds = array_values(array_filter(array_map('trim', $binds), fn (string $s) => $s !== ''));
        if ($binds === []) {
            throw new \RuntimeException('At least one `bind` line is required (e.g. `*:8080` or `127.0.0.1:7070`).');
        }

        $emit->step('haproxy-frontends', 'Reading current haproxy.cfg');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read haproxy.cfg from the server.');
        }

        foreach ($this->findFrontendBlocks($contents) as $block) {
            if ($block['name'] === $name) {
                throw new \RuntimeException("A frontend named `{$name}` already exists. Use a different name.");
            }
        }

        $rendered = $this->renderFrontend($name, $binds, $values);
        $newContents = rtrim($contents, "\n")."\n\n".$rendered."\n";

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'add frontend '.$name);
        $emit->success('Frontend '.$name.' added.');
    }

    /**
     * @throws \RuntimeException
     */
    public function removeFrontend(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('haproxy-frontends', 'Reading current haproxy.cfg');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read haproxy.cfg from the server.');
        }

        $found = false;
        $newContents = $contents;
        foreach ($this->findFrontendBlocks($contents) as $block) {
            if ($block['name'] === $name) {
                $pattern = '/'.preg_quote($block['raw'], '/').'\R?\R?/';
                $newContents = preg_replace($pattern, '', $newContents, 1) ?? $newContents;
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("No frontend named `{$name}` found.");
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'remove frontend '.$name);
        $emit->success('Frontend '.$name.' removed.');
    }

    /**
     * Build the canonical `frontend <name>` text. bind / mode / default_backend
     * / option-* / maxconn lines are emitted in a stable order so `haproxy fmt`
     * (no such command, but) and operator-readable diffs stay clean.
     *
     * @param  list<string>  $binds
     * @param  array<string, string>  $values
     */
    private function renderFrontend(string $name, array $binds, array $values): string
    {
        $lines = ['frontend '.$name];
        foreach ($binds as $b) {
            $b = trim((string) $b);
            if ($b !== '') {
                $lines[] = "\tbind ".$b;
            }
        }
        $mode = trim((string) ($values['mode'] ?? ''));
        if ($mode !== '') {
            $lines[] = "\tmode ".$mode;
        }
        $maxconn = trim((string) ($values['maxconn'] ?? ''));
        if ($maxconn !== '' && preg_match('/^\d+$/', $maxconn) === 1) {
            $lines[] = "\tmaxconn ".$maxconn;
        }
        $defaultBackend = trim((string) ($values['default_backend'] ?? ''));
        if ($defaultBackend !== '') {
            $lines[] = "\tdefault_backend ".$defaultBackend;
        }
        foreach (['httplog', 'dontlognull', 'forwardfor'] as $opt) {
            if (($values['option '.$opt] ?? '0') === '1') {
                $lines[] = "\toption ".$opt;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, body: string, raw: string}>
     */
    private function findFrontendBlocks(string $contents): array
    {
        $out = [];
        if (preg_match_all('/^frontend\s+(\S+)/m', $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
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
        $emit->step('haproxy-frontends', 'Staging new haproxy.cfg to /tmp ('.$reason.')');
        $tmpRemote = '/tmp/dply-haproxy.cfg.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new haproxy.cfg');
            throw new \RuntimeException('Failed to stage the new haproxy.cfg.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('haproxy-frontends', 'Snapshotting current haproxy.cfg to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('haproxy-frontends', 'Installing new haproxy.cfg at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous haproxy.cfg left in place');
            throw new \RuntimeException('Failed to install the new haproxy.cfg.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('haproxy-frontends', 'Validating with `haproxy -c -f`');
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
            $emit->step('haproxy-frontends', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous haproxy.cfg restored.');
            throw new \RuntimeException('Config validation failed; previous haproxy.cfg restored. haproxy -c output:'."\n".trim($stripped));
        }
        $emit->success('haproxy.cfg validated.');

        $emit->step('haproxy-frontends', 'Reloading HAProxy');
        $reload = $ssh->exec('sudo -n systemctl reload haproxy 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart haproxy 2>&1', 30);
        }
    }
}
