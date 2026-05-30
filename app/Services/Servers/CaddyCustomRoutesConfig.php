<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use App\Support\Servers\CaddyPhpFpmUpstreamAddress;
use App\Support\Servers\CaddyRuntimeOwnership;

/**
 * Ad-hoc Caddy site blocks outside dply's per-site provisioner.
 * Each route is a standalone file:
 *   /etc/caddy/sites-enabled/dply-custom-{slug}.caddy
 * picked up by `import /etc/caddy/sites-enabled/*.caddy` in the main Caddyfile.
 */
class CaddyCustomRoutesConfig
{
    public const FILE_PREFIX = 'dply-custom-';

    private const IMPORT_LINE = 'import /etc/caddy/sites-enabled/*.caddy';

    /**
     * @return array{routes: list<array{slug: string, path: string, hosts: list<string>, root: string, upstream: string}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        $enabled = rtrim((string) config('sites.caddy_sites_enabled'), '/');

        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec(
                'sudo -n ls -1 '.escapeshellarg($enabled.'/'.self::FILE_PREFIX).'*.caddy 2>/dev/null || true',
                15,
            );
        } catch (\Throwable) {
            return ['routes' => [], 'unreadable' => true];
        }

        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: [])));
        if ($paths === []) {
            return ['routes' => [], 'unreadable' => false];
        }

        $routes = [];
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

            $basename = basename($path, '.caddy');
            $slug = str_starts_with($basename, self::FILE_PREFIX)
                ? substr($basename, strlen(self::FILE_PREFIX))
                : $basename;

            $routes[] = array_merge(
                ['slug' => $slug, 'path' => $path],
                $this->parseSiteBlock($contents),
            );
        }

        usort($routes, fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        return ['routes' => $routes, 'unreadable' => false];
    }

    /**
     * @param  array{hosts: list<string>|string, root: string, upstream: string}  $fields
     *
     * @throws \RuntimeException
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);

        foreach ($this->read($server)['routes'] as $route) {
            if (($route['slug'] ?? '') === $slug) {
                throw new \RuntimeException("A custom route `{$slug}` already exists.");
            }
        }

        $contents = $this->render($server, $slug, $fields);
        $this->writeAndReload($server, $path, $contents, $emitter, 'add custom route '.$slug);
    }

    /**
     * @param  array{hosts: list<string>|string, root: string, upstream: string}  $fields
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);

        $exists = false;
        foreach ($this->read($server)['routes'] as $route) {
            if (($route['slug'] ?? '') === $slug) {
                $exists = true;
                break;
            }
        }
        if (! $exists) {
            throw new \RuntimeException("No custom route `{$slug}` found.");
        }

        $contents = $this->render($server, $slug, $fields);
        $this->writeAndReload($server, $path, $contents, $emitter, 'save custom route '.$slug);
    }

    /**
     * @throws \RuntimeException
     */
    public function remove(Server $server, string $slug, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $slug = $this->normalizeSlug($slug);
        $path = $this->pathForSlug($slug);

        $ssh = new SshConnection($server);
        $emit->step('caddy-custom-routes', 'Removing '.$path);
        $ssh->exec(sprintf(
            'sudo -n rm -f %s && %s && %s && (sudo -n systemctl reload caddy 2>/dev/null || sudo -n service caddy reload)',
            escapeshellarg($path),
            CaddyRuntimeOwnership::shell(),
            CaddyRuntimeOwnership::validateCommand(),
        ), 30);

        if ($ssh->lastExecExitCode() !== 0) {
            $emit->error('Failed to remove custom route or reload Caddy.');
            throw new \RuntimeException('Failed to remove custom route or reload Caddy.');
        }

        $emit->success('Custom route '.$slug.' removed.');
    }

    /**
     * @param  array{hosts: list<string>|string, root: string, upstream: string}  $fields
     */
    public function render(Server $server, string $slug, array $fields): string
    {
        $hosts = $this->normalizeList($fields['hosts'] ?? []);
        $root = trim((string) ($fields['root'] ?? ''));
        $upstream = trim((string) ($fields['upstream'] ?? ''));
        $upstream = $this->resolvePhpUpstream($server, $upstream);

        if ($hosts === []) {
            throw new \InvalidArgumentException('At least one hostname is required.');
        }

        $handler = $this->handlerKind($upstream);
        if ($handler !== 'reverse_proxy' && $root === '') {
            throw new \InvalidArgumentException('Document root is required for static and PHP routes.');
        }

        $hostLine = implode(', ', $hosts);
        $logBasename = self::FILE_PREFIX.$slug;

        $lines = [
            '# dply custom route: '.$slug,
            $hostLine.' {',
            '    encode zstd gzip',
            '    log {',
            '        output file /var/log/caddy/'.$logBasename.'-access.log',
            '    }',
        ];

        if ($root !== '') {
            $lines[] = '    root * '.rtrim($root, '/');
        }

        if ($handler === 'php') {
            $lines[] = '    php_fastcgi '.$this->formatPhpFastcgiTarget($upstream);
            $lines[] = '    file_server';
        } elseif ($handler === 'reverse_proxy') {
            $lines[] = '    reverse_proxy '.$this->formatReverseProxyTarget($upstream);
        } else {
            $lines[] = '    file_server';
        }

        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    private function writeAndReload(Server $server, string $path, string $contents, ?ConsoleEmitter $emitter, string $reason): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);

        $emit->step('caddy-custom-routes', 'Ensuring sites-enabled import in Caddyfile');
        $this->ensureSitesImportLine($server, $emit);

        $emit->step('caddy-custom-routes', 'Writing '.$path.' ('.$reason.')');
        $result = app(RemoteWebserverConfigService::class)->write($server, 'caddy', $path, $contents, $emit);

        if (! ($result['validate_ok'] ?? false)) {
            throw new \RuntimeException(trim((string) ($result['validate_output'] ?? 'caddy validate rejected the new file.')));
        }

        $ssh = new SshConnection($server);
        $emit->step('caddy-custom-routes', 'Reloading Caddy');
        $ssh->exec('sudo -n systemctl reload caddy 2>/dev/null || sudo -n service caddy reload', 20);

        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('File saved but Caddy reload failed.');
        }

        $emit->success('Custom route saved and Caddy reloaded.');
    }

    private function ensureSitesImportLine(Server $server, ConsoleEmitter $emit): void
    {
        $caddyfile = '/etc/caddy/Caddyfile';
        $ssh = new SshConnection($server);
        $ssh->exec(sprintf(
            'sudo -n mkdir -p /etc/caddy/sites-enabled /var/log/caddy && sudo -n touch %1$s && (sudo -n grep -Fqx %2$s %1$s || printf "\n%%s\n" %3$s | sudo -n tee -a %1$s > /dev/null)',
            escapeshellarg($caddyfile),
            escapeshellarg(self::IMPORT_LINE),
            escapeshellarg(self::IMPORT_LINE),
        ), 20);

        if ($ssh->lastExecExitCode() !== 0) {
            $emit->warn('Could not ensure Caddyfile import line — verify /etc/caddy/Caddyfile manually.');
        }
    }

    private function pathForSlug(string $slug): string
    {
        return rtrim((string) config('sites.caddy_sites_enabled'), '/').'/'.self::FILE_PREFIX.$slug.'.caddy';
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
     */
    private function normalizeList(array|string $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        return array_values(array_filter(array_map('trim', $value), fn (string $s): bool => $s !== ''));
    }

    private function handlerKind(string $upstream): string
    {
        if ($upstream === '') {
            return 'static';
        }

        if (str_starts_with($upstream, 'http://') || str_starts_with($upstream, 'https://')) {
            return 'reverse_proxy';
        }

        if (str_starts_with($upstream, 'unix:') || str_contains(strtolower($upstream), 'php')) {
            return 'php';
        }

        if (preg_match('/^[\d.:]+$/', $upstream) === 1 || str_contains($upstream, ':')) {
            return 'reverse_proxy';
        }

        return 'php';
    }

    private function resolvePhpUpstream(Server $server, string $upstream): string
    {
        if ($this->handlerKind($upstream) !== 'php') {
            return $upstream;
        }

        $configured = CaddyPhpFpmUpstreamAddress::phpVersionFromUpstream($upstream);
        $resolved = app(ServerPhpManager::class)->resolveCaddyPhpVersion($server, $configured);

        return CaddyPhpFpmUpstreamAddress::rewriteUpstreamToVersion($upstream, $resolved);
    }

    private function formatPhpFastcgiTarget(string $upstream): string
    {
        $target = $upstream;
        if (str_starts_with($target, 'unix:')) {
            $target = substr($target, 5);
        }

        return 'unix//'.ltrim($target, '/');
    }

    private function formatReverseProxyTarget(string $upstream): string
    {
        if (str_starts_with($upstream, 'http://') || str_starts_with($upstream, 'https://')) {
            return $upstream;
        }

        if (! str_contains($upstream, '://') && str_contains($upstream, ':')) {
            return 'http://'.$upstream;
        }

        return $upstream;
    }

    /**
     * @return array{hosts: list<string>, root: string, upstream: string}
     */
    private function parseSiteBlock(string $contents): array
    {
        $hosts = [];
        $root = '';
        $upstream = '';

        if (preg_match('/^([^{]+)\{/m', $contents, $header) === 1) {
            $hostPart = trim(preg_replace('/^#.*$/m', '', $header[1]) ?? '');
            foreach (preg_split('/\s*,\s*/', $hostPart) ?: [] as $host) {
                $host = trim($host);
                if ($host !== '' && ! str_starts_with($host, '#')) {
                    $hosts[] = $host;
                }
            }
        }

        if (preg_match('/^\s*root\s+\*\s+(\S+)/m', $contents, $m) === 1) {
            $root = trim($m[1]);
        }

        if (preg_match('/^\s*php_fastcgi\s+(\S+)/m', $contents, $m) === 1) {
            $target = trim($m[1]);
            $upstream = str_starts_with($target, 'unix//')
                ? 'unix:/'.substr($target, 6)
                : $target;
        } elseif (preg_match('/^\s*reverse_proxy\s+(\S+)/m', $contents, $m) === 1) {
            $upstream = trim($m[1]);
        }

        return [
            'hosts' => array_values(array_unique($hosts)),
            'root' => $root,
            'upstream' => $upstream,
        ];
    }
}
