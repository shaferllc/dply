<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\Concerns\WritesTraefikDynamicYaml;
use App\Services\SshConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * UDP routers + services in /etc/traefik/dynamic/dply-udp-{slug}.yml
 */
class TraefikUdpRoutesConfig
{
    use WritesTraefikDynamicYaml;

    public const FILE_PREFIX = 'dply-udp-';

    private const DYNAMIC_DIR = '/etc/traefik/dynamic';

    /**
     * @return array{routes: list<array{slug: string, path: string, entry_points: list<string>, server_address: string}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec('sudo -n ls -1 '.escapeshellarg(self::DYNAMIC_DIR.'/'.self::FILE_PREFIX).'*.yml 2>/dev/null || true', 15);
        } catch (\Throwable) {
            return ['routes' => [], 'unreadable' => true];
        }

        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: [])));
        $routes = [];
        foreach ($paths as $path) {
            try {
                $ssh = new SshConnection($server);
                $contents = $ssh->exec('sudo -n cat '.escapeshellarg($path).' 2>/dev/null', 15);
                if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                    continue;
                }
                $parsed = Yaml::parse($contents);
            } catch (\Throwable) {
                continue;
            }
            if (! is_array($parsed)) {
                continue;
            }
            $routers = is_array($parsed['udp']['routers'] ?? null) ? $parsed['udp']['routers'] : [];
            foreach ($routers as $rName => $def) {
                if (! is_string($rName) || ! str_starts_with($rName, self::FILE_PREFIX) || ! is_array($def)) {
                    continue;
                }
                $slug = substr($rName, strlen(self::FILE_PREFIX));
                $svc = is_array($parsed['udp']['services'][$rName] ?? null) ? $parsed['udp']['services'][$rName] : [];
                $lb = is_array($svc['loadBalancer'] ?? null) ? $svc['loadBalancer'] : [];
                $servers = is_array($lb['servers'] ?? null) ? $lb['servers'] : [];
                $addr = is_array($servers[0] ?? null) ? (string) ($servers[0]['address'] ?? '') : '';

                $routes[] = [
                    'slug' => $slug,
                    'path' => $path,
                    'entry_points' => is_array($def['entryPoints'] ?? null) ? array_values($def['entryPoints']) : [],
                    'server_address' => $addr,
                ];
                break;
            }
        }

        return ['routes' => $routes, 'unreadable' => false];
    }

    /**
     * @param  array{entry_points?: list<string>|string, server_address: string}  $fields
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeTraefikSlug($slug);
        foreach ($this->read($server)['routes'] as $row) {
            if (($row['slug'] ?? '') === $slug) {
                throw new \RuntimeException("UDP route `{$slug}` already exists.");
            }
        }
        $this->writeTraefikDynamicFile($server, $this->path($slug), $this->render($slug, $fields), $emitter, 'add UDP route');
    }

    /**
     * @param  array{entry_points?: list<string>|string, server_address: string}  $fields
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $this->writeTraefikDynamicFile($server, $this->path($this->normalizeTraefikSlug($slug)), $this->render($slug, $fields), $emitter, 'save UDP route');
    }

    public function remove(Server $server, string $slug, ?ConsoleEmitter $emitter = null): void
    {
        $this->removeTraefikDynamicFile($server, $this->path($this->normalizeTraefikSlug($slug)), $emitter);
    }

    /**
     * @param  array{entry_points?: list<string>|string, server_address: string}  $fields
     */
    public function render(string $slug, array $fields): string
    {
        $name = self::FILE_PREFIX.$slug;
        $entryPoints = $this->csvList($fields['entry_points'] ?? ['web']);
        if ($entryPoints === []) {
            $entryPoints = ['web'];
        }
        $addr = trim((string) ($fields['server_address'] ?? ''));
        if ($addr === '') {
            throw new \InvalidArgumentException('Backend address is required.');
        }
        $epYaml = implode("\n", array_map(static fn (string $ep): string => '        - '.$ep, $entryPoints));

        return <<<YAML
# Managed by Dply — UDP route (hot-reloaded).
udp:
  routers:
    {$name}:
      entryPoints:
{$epYaml}
      service: {$name}
  services:
    {$name}:
      loadBalancer:
        servers:
          - address: "{$addr}"
YAML;
    }

    protected function path(string $slug): string
    {
        return self::DYNAMIC_DIR.'/'.self::FILE_PREFIX.$slug.'.yml';
    }
}
