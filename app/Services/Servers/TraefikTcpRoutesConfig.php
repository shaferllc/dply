<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\Concerns\WritesTraefikDynamicYaml;
use App\Services\SshConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * TCP routers + services in /etc/traefik/dynamic/dply-tcp-{slug}.yml
 */
class TraefikTcpRoutesConfig
{
    use WritesTraefikDynamicYaml;

    public const FILE_PREFIX = 'dply-tcp-';

    private const DYNAMIC_DIR = '/etc/traefik/dynamic';

    /**
     * @return array{routes: list<array{slug: string, path: string, rule: string, entry_points: list<string>, server_address: string}>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        return $this->readByPrefix($server, self::FILE_PREFIX, 'tcp');
    }

    /**
     * @param  array{rule?: string, entry_points?: list<string>|string, server_address: string}  $fields
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeTraefikSlug($slug);
        foreach ($this->read($server)['routes'] as $row) {
            if (($row['slug'] ?? '') === $slug) {
                throw new \RuntimeException("TCP route `{$slug}` already exists.");
            }
        }
        $this->writeTraefikDynamicFile($server, $this->path($slug), $this->render($slug, $fields), $emitter, 'add TCP route');
    }

    /**
     * @param  array{rule?: string, entry_points?: list<string>|string, server_address: string}  $fields
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeTraefikSlug($slug);
        $this->writeTraefikDynamicFile($server, $this->path($slug), $this->render($slug, $fields), $emitter, 'save TCP route');
    }

    public function remove(Server $server, string $slug, ?ConsoleEmitter $emitter = null): void
    {
        $this->removeTraefikDynamicFile($server, $this->path($this->normalizeTraefikSlug($slug)), $emitter);
    }

    /**
     * @param  array{rule?: string, entry_points?: list<string>|string, server_address: string}  $fields
     */
    public function render(string $slug, array $fields): string
    {
        $name = self::FILE_PREFIX.$slug;
        $rule = trim((string) ($fields['rule'] ?? '')) ?: 'HostSNI(`*`)';
        $entryPoints = $this->csvList($fields['entry_points'] ?? ['web']);
        if ($entryPoints === []) {
            $entryPoints = ['web'];
        }
        $addr = trim((string) ($fields['server_address'] ?? ''));
        if ($addr === '') {
            throw new \InvalidArgumentException('Backend address is required (e.g. :3306 or 127.0.0.1:6379).');
        }
        $epYaml = implode("\n", array_map(static fn (string $ep): string => '        - '.$ep, $entryPoints));

        return <<<YAML
# Managed by Dply — TCP route (hot-reloaded).
tcp:
  routers:
    {$name}:
      rule: "{$rule}"
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

    /**
     * @return array{routes: list<array<string, mixed>>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    protected function readByPrefix(Server $server, string $prefix, string $layer): array
    {
        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec('sudo -n ls -1 '.escapeshellarg(self::DYNAMIC_DIR.'/'.$prefix).'*.yml 2>/dev/null || true', 15);
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
            $routers = is_array($parsed[$layer]['routers'] ?? null) ? $parsed[$layer]['routers'] : [];
            foreach ($routers as $rName => $def) {
                if (! is_string($rName) || ! str_starts_with($rName, $prefix) || ! is_array($def)) {
                    continue;
                }
                $slug = substr($rName, strlen($prefix));
                $allServices = is_array($parsed[$layer]['services'] ?? null) ? $parsed[$layer]['services'] : [];
                $svcDef = is_array($allServices[$rName] ?? null) ? $allServices[$rName] : [];
                $lb = is_array($svcDef['loadBalancer'] ?? null) ? $svcDef['loadBalancer'] : [];
                $servers = is_array($lb['servers'] ?? null) ? $lb['servers'] : [];
                $addr = is_array($servers[0] ?? null) ? (string) ($servers[0]['address'] ?? '') : '';
                $routes[] = [
                    'slug' => $slug,
                    'path' => $path,
                    'rule' => (string) ($def['rule'] ?? ''),
                    'entry_points' => is_array($def['entryPoints'] ?? null) ? array_values($def['entryPoints']) : [],
                    'server_address' => $addr,
                ];
                break;
            }
        }

        return ['routes' => $routes, 'unreadable' => false];
    }

    protected function path(string $slug): string
    {
        return self::DYNAMIC_DIR.'/'.self::FILE_PREFIX.$slug.'.yml';
    }
}
