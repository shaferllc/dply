<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Ad-hoc nginx `server {}` blocks outside dply's per-site provisioner.
 * Each host is a standalone file:
 *   /etc/nginx/sites-available/dply-custom-{slug}.conf
 * symlinked into sites-enabled. Save / add / remove run through
 * {@see RemoteWebserverConfigService} (snapshot → install → nginx -t)
 * with auto-revert on validation failure, then reload nginx.
 */
class NginxCustomHostsConfig
{
    public const FILE_PREFIX = 'dply-custom-';

    /**
     * @return array{hosts: list<array{slug: string, path: string, server_names: list<string>, listen: list<string>, root: string, upstream: string, ssl: bool}>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        $available = rtrim((string) config('sites.nginx_sites_available'), '/');

        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec(
                'sudo -n ls -1 '.escapeshellarg($available.'/'.self::FILE_PREFIX).'*.conf 2>/dev/null || true',
                15,
            );
        } catch (\Throwable) {
            return ['hosts' => [], 'unreadable' => true];
        }

        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: [])));
        if ($paths === []) {
            return ['hosts' => [], 'unreadable' => false];
        }

        $hosts = [];
        foreach ($paths as $path) {
            try {
                $ssh = new SshConnection($server);
                $contents = $ssh->exec('sudo -n cat '.escapeshellarg($path).' 2>/dev/null', 15);
                if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $basename = basename($path, '.conf');
            $slug = str_starts_with($basename, self::FILE_PREFIX)
                ? substr($basename, strlen(self::FILE_PREFIX))
                : $basename;

            $hosts[] = array_merge(
                ['slug' => $slug, 'path' => $path],
                $this->parseServerBlock($contents),
            );
        }

        usort($hosts, fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        return ['hosts' => $hosts, 'unreadable' => false];
    }

    /**
     * @param  array{server_names: list<string>|string, listen: list<string>|string, root: string, upstream: string, ssl?: bool}  $fields
     *
     * @throws \RuntimeException
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);

        foreach ($this->read($server)['hosts'] as $host) {
            if (($host['slug'] ?? '') === $slug) {
                throw new \RuntimeException("A custom host `{$slug}` already exists.");
            }
        }

        $contents = $this->render($slug, $fields);
        $this->writeAndEnable($server, $path, $contents, $emitter, 'add custom host '.$slug);
    }

    /**
     * @param  array{server_names: list<string>|string, listen: list<string>|string, root: string, upstream: string, ssl?: bool}  $fields
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);

        $exists = false;
        foreach ($this->read($server)['hosts'] as $host) {
            if (($host['slug'] ?? '') === $slug) {
                $exists = true;
                break;
            }
        }
        if (! $exists) {
            throw new \RuntimeException("No custom host `{$slug}` found.");
        }

        $contents = $this->render($slug, $fields);
        $this->writeAndEnable($server, $path, $contents, $emitter, 'save custom host '.$slug);
    }

    /**
     * @throws \RuntimeException
     */
    public function remove(Server $server, string $slug, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);
        $enabled = rtrim((string) config('sites.nginx_sites_enabled'), '/').'/'.basename($path);

        $ssh = new SshConnection($server);
        $emit->step('nginx-custom-hosts', 'Removing '.$path);
        $ssh->exec(sprintf(
            'sudo -n rm -f %s %s && (sudo -n nginx -t 2>&1 && (sudo -n systemctl reload nginx 2>/dev/null || sudo -n service nginx reload))',
            escapeshellarg($path),
            escapeshellarg($enabled),
        ), 30);

        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to remove custom host or reload nginx.');
            throw new \RuntimeException('Failed to remove custom host or reload nginx.');
        }

        $emit->success('Custom host '.$slug.' removed.');
    }

    /**
     * @param  array{server_names: list<string>|string, listen: list<string>|string, root: string, upstream: string, ssl?: bool}  $fields
     */
    public function render(string $slug, array $fields): string
    {
        $serverNames = $this->normalizeList($fields['server_names'] ?? []);
        $listens = $this->normalizeList($fields['listen'] ?? ['80', '[::]:80']);
        $root = trim((string) ($fields['root'] ?? ''));
        $upstream = trim((string) ($fields['upstream'] ?? ''));

        if ($serverNames === []) {
            throw new \InvalidArgumentException('At least one server_name is required.');
        }
        if ($root === '') {
            throw new \InvalidArgumentException('Document root is required.');
        }
        if ($listens === []) {
            throw new \InvalidArgumentException('At least one listen directive is required.');
        }

        $lines = [
            '# dply custom host: '.$slug,
            'server {',
        ];

        foreach ($listens as $listen) {
            $lines[] = '    listen '.$listen.';';
        }

        $lines[] = '    server_name '.implode(' ', $serverNames).';';
        $lines[] = '    root '.rtrim($root, '/').';';
        $lines[] = '    index index.html index.php;';
        $lines[] = '';
        $lines[] = '    location / {';
        $lines[] = '        try_files $uri $uri/ /index.php?$query_string;';
        $lines[] = '    }';

        if ($upstream !== '') {
            $passDirective = $this->upstreamDirective($upstream);
            $lines[] = '';
            $lines[] = '    location ~ \.php$ {';
            $lines[] = '        include snippets/fastcgi-php.conf;';
            $lines[] = '        '.$passDirective.' '.$upstream.';';
            $lines[] = '    }';
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    private function writeAndEnable(Server $server, string $path, string $contents, ?ConsoleEmitter $emitter, string $reason): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $enabled = rtrim((string) config('sites.nginx_sites_enabled'), '/').'/'.basename($path);

        $emit->step('nginx-custom-hosts', 'Writing '.$path.' ('.$reason.')');
        $result = app(RemoteWebserverConfigService::class)->write($server, 'nginx', $path, $contents, $emit);

        if (! ($result['validate_ok'])) {
            throw new \RuntimeException(trim((string) ($result['validate_output'] ?? 'nginx -t rejected the new file.')));
        }

        $ssh = new SshConnection($server);
        $emit->step('nginx-custom-hosts', 'Enabling '.$enabled);
        $ssh->exec(sprintf(
            'sudo -n ln -sf %s %s && (sudo -n systemctl reload nginx 2>/dev/null || sudo -n service nginx reload)',
            escapeshellarg($path),
            escapeshellarg($enabled),
        ), 20);

        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('File saved but nginx reload failed.');
        }

        $emit->success('Custom host saved and nginx reloaded.');
    }

    private function pathForSlug(string $slug): string
    {
        return rtrim((string) config('sites.nginx_sites_available'), '/').'/'.self::FILE_PREFIX.$slug.'.conf';
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            throw new \InvalidArgumentException('Slug is required and may only contain lowercase letters, digits, and hyphens.');
        }

        return $slug;
    }

    /**
     * @return list<string>
     * @param  array<string, mixed> $value
     */
    private function normalizeList(array|string $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        return array_values(array_filter(array_map('trim', $value), fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array{server_names: list<string>, listen: list<string>, root: string, upstream: string, ssl: bool}
     */
    private function parseServerBlock(string $contents): array
    {
        $serverNames = [];
        $listens = [];
        $root = '';
        $upstream = '';
        $ssl = false;

        if (preg_match_all('/^\s*server_name\s+([^;]+);/m', $contents, $nameMatches) > 0) {
            foreach ($nameMatches[1] as $val) {
                foreach (preg_split('/\s+/', trim($val)) ?: [] as $token) {
                    if ($token !== '') {
                        $serverNames[] = $token;
                    }
                }
            }
        }

        if (preg_match_all('/^\s*listen\s+([^;]+);/m', $contents, $listenMatches) > 0) {
            foreach ($listenMatches[1] as $val) {
                $listens[] = trim($val);
                if (str_contains(strtolower($val), 'ssl') || trim($val) === '443') {
                    $ssl = true;
                }
            }
        }

        if (preg_match('/^\s*root\s+([^;]+);/m', $contents, $m) === 1) {
            $root = trim($m[1]);
        }

        foreach (['fastcgi_pass', 'proxy_pass', 'uwsgi_pass'] as $directive) {
            if (preg_match('/^\s*'.$directive.'\s+([^;]+);/m', $contents, $m) === 1) {
                $upstream = trim($m[1]);
                break;
            }
        }

        return [
            'server_names' => array_values(array_unique($serverNames)),
            'listen' => $listens,
            'root' => $root,
            'upstream' => $upstream,
            'ssl' => $ssl,
        ];
    }

    private function upstreamDirective(string $upstream): string
    {
        if (str_starts_with($upstream, 'http://') || str_starts_with($upstream, 'https://')) {
            return 'proxy_pass';
        }

        return 'fastcgi_pass';
    }
}
