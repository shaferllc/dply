<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\Concerns\WritesTraefikDynamicYaml;
use App\Services\SshConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * Standalone HTTP services in /etc/traefik/dynamic/dply-svc-{slug}.yml
 */
class TraefikHttpServicesConfig
{
    use WritesTraefikDynamicYaml;

    public const FILE_PREFIX = 'dply-svc-';

    private const DYNAMIC_DIR = '/etc/traefik/dynamic';

    /**
     * @return array{services: list<array{slug: string, path: string, servers: list<string>}>, unreadable: bool}
     */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec('sudo -n ls -1 '.escapeshellarg(self::DYNAMIC_DIR.'/'.self::FILE_PREFIX).'*.yml 2>/dev/null || true', 15);
        } catch (\Throwable) {
            return ['services' => [], 'unreadable' => true];
        }

        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: [])));
        $rows = [];
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
            $services = is_array($parsed['http']['services'] ?? null) ? $parsed['http']['services'] : [];
            foreach ($services as $sName => $def) {
                if (! is_string($sName) || ! str_starts_with($sName, self::FILE_PREFIX) || ! is_array($def)) {
                    continue;
                }
                $lb = is_array($def['loadBalancer'] ?? null) ? $def['loadBalancer'] : [];
                $servers = is_array($lb['servers'] ?? null) ? $lb['servers'] : [];
                $urls = array_values(array_map(
                    static fn ($s): string => is_array($s) ? (string) ($s['url'] ?? '') : '',
                    $servers,
                ));
                $rows[] = [
                    'slug' => substr($sName, strlen(self::FILE_PREFIX)),
                    'path' => $path,
                    'servers' => array_filter($urls),
                ];
                break;
            }
        }

        return ['services' => $rows, 'unreadable' => false];
    }

    /**
     * @param  array{servers: list<string>|string}  $fields
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeTraefikSlug($slug);
        foreach ($this->read($server)['services'] as $row) {
            if (($row['slug'] ?? '') === $slug) {
                throw new \RuntimeException("Service `{$slug}` already exists.");
            }
        }
        $this->writeTraefikDynamicFile($server, $this->path($slug), $this->render($slug, $fields), $emitter, 'add HTTP service');
    }

    /**
     * @param  array{servers: list<string>|string}  $fields
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $this->writeTraefikDynamicFile($server, $this->path($this->normalizeTraefikSlug($slug)), $this->render($slug, $fields), $emitter, 'save HTTP service');
    }

    public function remove(Server $server, string $slug, ?ConsoleEmitter $emitter = null): void
    {
        $this->removeTraefikDynamicFile($server, $this->path($this->normalizeTraefikSlug($slug)), $emitter);
    }

    /**
     * @param  array{servers: list<string>|string}  $fields
     */
    public function render(string $slug, array $fields): string
    {
        $name = self::FILE_PREFIX.$slug;
        $urls = $this->csvList($fields['servers'] ?? []);
        if ($urls === []) {
            throw new \InvalidArgumentException('At least one server URL is required.');
        }
        $serverLines = '';
        foreach ($urls as $url) {
            if (! preg_match('#^https?://#i', $url)) {
                $url = 'http://'.$url;
            }
            $serverLines .= "          - url: \"{$url}\"\n";
        }

        return <<<YAML
# Managed by Dply — HTTP service (hot-reloaded).
http:
  services:
    {$name}:
      loadBalancer:
        servers:
{$serverLines}
YAML;
    }

    protected function path(string $slug): string
    {
        return self::DYNAMIC_DIR.'/'.self::FILE_PREFIX.$slug.'.yml';
    }
}
