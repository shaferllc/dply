<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Read + write nginx `upstream <name> { ... }` blocks in
 * `/etc/nginx/nginx.conf`. These are reusable backend pools that site
 * blocks reference via `proxy_pass http://<name>` or `fastcgi_pass`.
 *
 * Per-site upstream blocks (often defined inside a sites-enabled
 * fragment alongside the matching `server {}` block) pass through
 * untouched — dply's per-site provisioner owns those. This service only
 * touches upstream blocks at the `http { }` level of nginx.conf.
 *
 * Save / add / remove all run through:
 *   snapshot → atomic install → `nginx -t` → systemctl reload nginx
 * with auto-revert from .dply-bak.<ts> on validation failure.
 */
class NginxUpstreamsConfig
{
    private const REMOTE_PATH = '/etc/nginx/nginx.conf';

    /**
     * Per-server directive tunables. Servers are stored as one entry per
     * `server <addr> [opts...];` line so the operator can add/remove
     * backends without re-keying everything.
     *
     * Edit-time the UI surfaces a textarea (one server per line). Reader
     * parses each line into an `address + opts` tuple.
     *
     * @var array<string, array{type: string, default: string, label: string, help: string}>
     */
    public const POOL_PARAMS = [
        'keepalive' => [
            'type' => 'int',
            'default' => '',
            'label' => 'keepalive',
            'help' => 'Cache N idle keep-alive connections per worker. Blank = no keep-alive (default).',
        ],
        'keepalive_requests' => [
            'type' => 'int',
            'default' => '',
            'label' => 'keepalive_requests',
            'help' => 'Max requests a keep-alive connection can serve before closing. Blank = nginx default (1000 on 1.19+).',
        ],
        'keepalive_timeout' => [
            'type' => 'string',
            'default' => '',
            'label' => 'keepalive_timeout',
            'help' => 'How long an idle keep-alive connection stays open. e.g. `60s`. Blank = default.',
        ],
        'least_conn' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'least_conn',
            'help' => 'Pick the backend with fewest active connections. Default is round-robin.',
        ],
        'ip_hash' => [
            'type' => 'bool',
            'default' => '0',
            'label' => 'ip_hash',
            'help' => 'Pin each client IP to a specific backend (sticky sessions by IP).',
        ],
    ];

    /**
     * @return array{upstreams: list<array{name: string, servers: list<string>, values: array<string, string>, raw: string}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return ['upstreams' => [], 'unreadable' => true];
            }
        } catch (\Throwable) {
            return ['upstreams' => [], 'unreadable' => true];
        }

        $out = [];
        foreach ($this->findUpstreamBlocks($contents) as $block) {
            $servers = [];
            $values = array_map(fn (array $meta): string => (string) $meta['default'], self::POOL_PARAMS);

            foreach (preg_split('/\R/', $block['body']) ?: [] as $line) {
                $trim = trim($line);
                if ($trim === '' || $trim[0] === '#') {
                    continue;
                }
                if (preg_match('/^server\s+(.+);/', $trim, $m) === 1) {
                    $servers[] = trim($m[1]);

                    continue;
                }
                if (preg_match('/^(keepalive|keepalive_requests|keepalive_timeout)\s+([^;]+);/', $trim, $m) === 1) {
                    $values[$m[1]] = trim($m[2]);

                    continue;
                }
                if (preg_match('/^(least_conn|ip_hash)\s*;/', $trim, $m) === 1) {
                    $values[$m[1]] = '1';
                }
            }

            $out[] = [
                'name' => $block['name'],
                'servers' => $servers,
                'values' => $values,
                'raw' => $block['raw'],
            ];
        }

        return ['upstreams' => $out, 'unreadable' => false];
    }

    /**
     * Save edits across upstream blocks. `$updates` is keyed by upstream
     * name → ['servers' => list<string>, 'values' => array<string,string>].
     * Upstreams not in $updates pass through untouched.
     *
     * @param  array<string, array{servers: list<string>, values: array<string, string>}>  $updates
     * @throws \RuntimeException
     */
    public function save(Server $server, array $updates, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        if ($updates === []) {
            $emit->warn('No upstream updates supplied.');

            return;
        }

        $emit->step('nginx-upstreams', 'Reading current nginx.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read nginx.conf from the server.');
        }

        $newContents = $contents;
        $rewritten = 0;
        foreach ($this->findUpstreamBlocks($contents) as $block) {
            if (! array_key_exists($block['name'], $updates)) {
                continue;
            }
            $payload = $updates[$block['name']];
            $rendered = $this->renderUpstream(
                $block['name'],
                array_values((array) ($payload['servers'] ?? [])),
                (array) ($payload['values'] ?? []),
            );
            $newContents = str_replace($block['raw'], $rendered, $newContents);
            $rewritten++;
            $emit->info('[nginx-upstreams] Rewriting upstream: '.$block['name']);
        }

        if ($rewritten === 0) {
            $emit->warn('No matching upstream blocks were rewritten.');

            return;
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'rewrite '.$rewritten.' upstream(s)');
        $emit->success('nginx reloaded with the updated upstreams.');
    }

    /**
     * Append a new `upstream <name> { ... }` block inside the `http { }`
     * section (or at end-of-file if there's no http block, which would be
     * an unusual nginx.conf but we don't want to fail outright).
     *
     * @param  list<string>  $servers
     * @param  array<string, string>  $values
     * @throws \RuntimeException
     */
    public function addUpstream(Server $server, string $name, array $servers, array $values, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $name = trim($name);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new \RuntimeException('Name is required and may only contain letters, digits, `_`, `.`, or `-`.');
        }
        $servers = array_values(array_filter(array_map('trim', $servers), fn (string $s) => $s !== ''));
        if ($servers === []) {
            throw new \RuntimeException('At least one `server` line is required.');
        }

        $emit->step('nginx-upstreams', 'Reading current nginx.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read nginx.conf from the server.');
        }

        foreach ($this->findUpstreamBlocks($contents) as $block) {
            if ($block['name'] === $name) {
                throw new \RuntimeException("An upstream named `{$name}` already exists. Use a different name.");
            }
        }

        $rendered = $this->renderUpstream($name, $servers, $values);
        // Find the closing brace of the http block and insert just before it.
        $httpBlock = $this->findNamedBlock($contents, 'http');
        if ($httpBlock !== null) {
            [, $bodyEnd] = $httpBlock;
            $newContents = substr($contents, 0, $bodyEnd)
                ."\n\t".str_replace("\n", "\n\t", rtrim($rendered, "\n"))."\n"
                .substr($contents, $bodyEnd);
        } else {
            $newContents = rtrim($contents, "\n")."\n\n".$rendered."\n";
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'add upstream '.$name);
        $emit->success('Upstream '.$name.' added.');
    }

    /**
     * Strip an upstream block. Warns when sites still reference it via
     * `proxy_pass http://<name>` / `fastcgi_pass <name>` — nginx -t will
     * fail in that case, but the warning surfaces upfront.
     *
     * @throws \RuntimeException
     */
    public function removeUpstream(Server $server, string $name, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $ssh = new SshConnection($server);

        $emit->step('nginx-upstreams', 'Reading current nginx.conf');
        $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::REMOTE_PATH), 15);
        if ($ssh->lastExecExitCode() !== 0 || $contents === '') {
            $emit->error('Could not read '.self::REMOTE_PATH);
            throw new \RuntimeException('Could not read nginx.conf from the server.');
        }

        $found = false;
        $newContents = $contents;
        foreach ($this->findUpstreamBlocks($contents) as $block) {
            if ($block['name'] === $name) {
                $pattern = '/'.preg_quote($block['raw'], '/').'\R?\R?/';
                $newContents = preg_replace($pattern, '', $newContents, 1) ?? $newContents;
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("No upstream named `{$name}` found.");
        }

        // Best-effort dependency scan across nginx -T resolved tree.
        $refScan = $ssh->exec("sudo -n nginx -T 2>/dev/null | grep -E '(proxy_pass|fastcgi_pass)\\s+(http://)?{$name}([ ;/])' | head -n 3 || true", 15);
        if (trim($refScan) !== '') {
            $emit->warn('Other directives still reference `'.$name.'`. nginx -t will likely fail.');
        }

        $this->stageInstallValidateReload($ssh, $emit, $newContents, 'remove upstream '.$name);
        $emit->success('Upstream '.$name.' removed.');
    }

    /**
     * Build the canonical `upstream <name> { ... }` text from the form data.
     *
     * @param  list<string>  $servers
     * @param  array<string, string>  $values
     */
    private function renderUpstream(string $name, array $servers, array $values): string
    {
        $lines = ['upstream '.$name.' {'];
        foreach (self::POOL_PARAMS as $key => $meta) {
            $raw = trim((string) ($values[$key] ?? $meta['default']));
            if ($raw === '' || ($meta['type'] === 'bool' && $raw !== '1')) {
                continue;
            }
            if ($meta['type'] === 'bool') {
                $lines[] = "\t".$key.';';

                continue;
            }
            $lines[] = "\t".$key.' '.$raw.';';
        }
        foreach ($servers as $srv) {
            $srv = trim((string) $srv);
            if ($srv === '') {
                continue;
            }
            // If the operator entered the trailing semicolon, strip it — we add one.
            $srv = rtrim($srv, ';');
            $lines[] = "\tserver ".$srv.';';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, body: string, raw: string}>
     */
    private function findUpstreamBlocks(string $contents): array
    {
        $out = [];
        if (preg_match_all('/^[\t ]*upstream\s+(\S+)\s*\{/m', $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
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
            $out[] = [
                'name' => $name,
                'body' => substr($contents, $bodyStart, $end - $bodyStart),
                'raw' => substr($contents, $rawStart, $end - $rawStart + 1),
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
     * @return array{0: int, 1: int}|null [body-start, body-end] for `<name> { ... }`
     */
    private function findNamedBlock(string $contents, string $blockName): ?array
    {
        if (preg_match('/^[\t ]*'.preg_quote($blockName, '/').'\s*\{/m', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $bodyStart = $m[0][1] + strlen($m[0][0]);
        $end = $this->findMatchingClose($contents, $bodyStart);
        if ($end === null) {
            return null;
        }

        return [$bodyStart, $end];
    }

    private function stageInstallValidateReload(SshConnection $ssh, ConsoleEmitter $emit, string $newContents, string $reason): void
    {
        $emit->step('nginx-upstreams', 'Staging new nginx.conf to /tmp ('.$reason.')');
        $tmpRemote = '/tmp/dply-nginx.conf.'.bin2hex(random_bytes(6));
        $encoded = base64_encode($newContents);
        $ssh->exec(sprintf('printf %s | base64 -d | sudo -n tee %s > /dev/null', escapeshellarg($encoded), escapeshellarg($tmpRemote)), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to stage the new nginx.conf');
            throw new \RuntimeException('Failed to stage the new nginx.conf.');
        }

        $bak = self::REMOTE_PATH.'.dply-bak.'.now()->format('YmdHis');
        $emit->step('nginx-upstreams', 'Snapshotting current nginx.conf to '.$bak);
        $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg(self::REMOTE_PATH), escapeshellarg($bak)), 10);

        $emit->step('nginx-upstreams', 'Installing new nginx.conf at '.self::REMOTE_PATH);
        $ssh->exec(sprintf('sudo -n install -m 0644 -T %s %s', escapeshellarg($tmpRemote), escapeshellarg(self::REMOTE_PATH)), 10);
        if ($ssh->lastExecExitCode() !== 0) {
            $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);
            $emit->error('install failed — previous nginx.conf left in place');
            throw new \RuntimeException('Failed to install the new nginx.conf.');
        }
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmpRemote), 5);

        $emit->step('nginx-upstreams', 'Validating with `nginx -t`');
        $validate = $ssh->exec('sudo -n nginx -t 2>&1; echo "__exit__:$?"', 30);
        $exit = (preg_match('/__exit__:(\d+)\s*$/', $validate, $vm) === 1) ? (int) $vm[1] : 1;
        $stripped = (string) preg_replace('/__exit__:\d+\s*$/', '', $validate);
        foreach (preg_split('/\R/', trim($stripped)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $emit($line, $exit !== 0 ? \App\Models\ConsoleAction::LEVEL_WARN : \App\Models\ConsoleAction::LEVEL_INFO);
            }
        }
        if ($exit !== 0) {
            $emit->step('nginx-upstreams', 'Validation failed — restoring '.$bak);
            $ssh->exec(sprintf('sudo -n cp -p %s %s', escapeshellarg($bak), escapeshellarg(self::REMOTE_PATH)), 10);
            $emit->error('Config validation failed; previous nginx.conf restored.');
            throw new \RuntimeException('Config validation failed; previous nginx.conf restored. nginx -t output:'."\n".trim($stripped));
        }
        $emit->success('nginx.conf validated.');

        $emit->step('nginx-upstreams', 'Reloading nginx');
        $reload = $ssh->exec('sudo -n systemctl reload nginx 2>&1; echo "__exit__:$?"', 20);
        $reloadExit = (preg_match('/__exit__:(\d+)\s*$/', $reload, $rm) === 1) ? (int) $rm[1] : 1;
        if ($reloadExit !== 0) {
            $emit->warn('Reload returned non-zero — falling back to restart.');
            $ssh->exec('sudo -n systemctl restart nginx 2>&1', 30);
        }
    }
}
