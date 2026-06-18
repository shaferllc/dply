<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;
use Symfony\Component\Yaml\Yaml;

/**
 * Operator-defined HTTP routers in /etc/traefik/dynamic/dply-custom-{slug}.yml.
 */
class TraefikCustomRoutesConfig
{
    public const FILE_PREFIX = 'dply-custom-';

    private const DYNAMIC_DIR = '/etc/traefik/dynamic';

    /**
     * @return array{routes: list<array{slug: string, path: string, hosts: list<string>, rule: string, upstream: string, middlewares: list<string>}>, unreadable: bool}
     */
    /** @return array<string, mixed> */
    public function read(Server $server): array
    {
        try {
            $ssh = new SshConnection($server);
            $listing = $ssh->exec(
                'sudo -n ls -1 '.escapeshellarg(self::DYNAMIC_DIR.'/'.self::FILE_PREFIX).'*.yml 2>/dev/null || true',
                15,
            );
        } catch (\Throwable) {
            return ['routes' => [], 'unreadable' => true];
        }

        $paths = array_values(array_filter(array_map('trim', preg_split('/\R/', trim($listing)) ?: [])));
        $routes = [];
        foreach ($paths as $path) {
            if (str_contains(basename($path), 'dply-dashboard')) {
                continue;
            }
            try {
                $ssh = new SshConnection($server);
                $contents = $ssh->exec('sudo -n cat '.escapeshellarg($path).' 2>/dev/null', 15);
                if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $basename = basename($path, '.yml');
            $slug = str_starts_with($basename, self::FILE_PREFIX)
                ? substr($basename, strlen(self::FILE_PREFIX))
                : $basename;

            $routes[] = array_merge(
                ['slug' => $slug, 'path' => $path],
                $this->parseRouteFile($contents),
            );
        }

        usort($routes, fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        return ['routes' => $routes, 'unreadable' => false];
    }

    /**
     * @param  array{hosts: list<string>|string, upstream: string, rule?: string, middlewares?: list<string>|string}  $fields
     *
     * @throws \RuntimeException
     */
    public function add(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        foreach ($this->read($server)['routes'] as $route) {
            if (($route['slug'] ?? '') === $slug) {
                throw new \RuntimeException("A custom route `{$slug}` already exists.");
            }
        }

        $this->write($server, $slug, $fields, $emitter, 'add custom route '.$slug);
    }

    /**
     * @param  array{hosts: list<string>|string, upstream: string, rule?: string, middlewares?: list<string>|string}  $fields
     *
     * @throws \RuntimeException
     */
    public function save(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter = null): void
    {
        $slug = $this->normalizeSlug($slug);
        $found = false;
        foreach ($this->read($server)['routes'] as $route) {
            if (($route['slug'] ?? '') === $slug) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new \RuntimeException("No custom route `{$slug}` found.");
        }

        $this->write($server, $slug, $fields, $emitter, 'save custom route '.$slug);
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
        $emit->step('traefik-custom-routes', 'Removing '.$path);
        $ssh->exec('sudo -n rm -f '.escapeshellarg($path), 15);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('Failed to remove custom route file.');
        }
        $emit->success('Custom route '.$slug.' removed (file provider will hot-reload).');
    }

    /**
     * @param  array{hosts: list<string>|string, upstream: string, rule?: string, middlewares?: list<string>|string}  $fields
     */
    public function render(string $slug, array $fields): string
    {
        $hosts = $this->hostsList($fields['hosts'] ?? []);
        if ($hosts === []) {
            throw new \InvalidArgumentException('At least one hostname is required.');
        }
        $upstream = trim((string) ($fields['upstream'] ?? ''));
        if ($upstream === '') {
            throw new \InvalidArgumentException('Upstream URL is required (e.g. http://127.0.0.1:3000).');
        }
        if (! preg_match('#^https?://#i', $upstream)) {
            $upstream = 'http://'.$upstream;
        }

        $routerName = self::FILE_PREFIX.$slug;
        $rule = trim((string) ($fields['rule'] ?? ''));
        if ($rule === '') {
            $rule = collect($hosts)->map(fn (string $h): string => 'Host(`'.$h.'`)')->implode(' || ');
        }

        $middlewares = $this->middlewaresList($fields['middlewares'] ?? []);
        $middlewaresYaml = '';
        $routerMiddlewaresYaml = '';
        if ($middlewares !== []) {
            $routerMiddlewaresYaml = "      middlewares:\n".implode("\n", array_map(
                static fn (string $m): string => '        - '.$m,
                $middlewares,
            ))."\n";
        }

        return <<<YAML
# Managed by Dply — custom HTTP route (hot-reloaded).
http:
  routers:
    {$routerName}:
      entryPoints:
        - web
      rule: "{$rule}"
      service: {$routerName}
{$routerMiddlewaresYaml}  services:
    {$routerName}:
      loadBalancer:
        servers:
          - url: "{$upstream}"
YAML;
    }

    /**
     * @return array{hosts: list<string>, rule: string, upstream: string, middlewares: list<string>}
     */
    private function parseRouteFile(string $contents): array
    {
        try {
            $parsed = Yaml::parse($contents);
        } catch (\Throwable) {
            return ['hosts' => [], 'rule' => '', 'upstream' => '', 'middlewares' => []];
        }
        if (! is_array($parsed)) {
            return ['hosts' => [], 'rule' => '', 'upstream' => '', 'middlewares' => []];
        }

        $routers = is_array($parsed['http']['routers'] ?? null) ? $parsed['http']['routers'] : [];
        $router = null;
        foreach ($routers as $name => $def) {
            if (is_string($name) && str_starts_with($name, self::FILE_PREFIX) && is_array($def)) {
                $router = $def;
                break;
            }
        }

        $rule = is_array($router) ? (string) ($router['rule'] ?? '') : '';
        $hosts = [];
        if (preg_match_all('/Host\(`([^`]+)`\)/', $rule, $hm) > 0) {
            $hosts = array_values($hm[1]);
        }

        $middlewares = is_array($router['middlewares'] ?? null) ? array_values(array_map('strval', $router['middlewares'])) : [];

        $services = is_array($parsed['http']['services'] ?? null) ? $parsed['http']['services'] : [];
        $upstream = '';
        foreach ($services as $def) {
            if (! is_array($def)) {
                continue;
            }
            $lb = is_array($def['loadBalancer'] ?? null) ? $def['loadBalancer'] : [];
            $servers = is_array($lb['servers'] ?? null) ? $lb['servers'] : [];
            $first = $servers[0] ?? null;
            if (is_array($first) && isset($first['url'])) {
                $upstream = (string) $first['url'];
                break;
            }
        }

        return ['hosts' => $hosts, 'rule' => $rule, 'upstream' => $upstream, 'middlewares' => $middlewares];
    }

    /**
     * @param  array{hosts: list<string>|string, upstream: string, rule?: string, middlewares?: list<string>|string}  $fields
     */
    private function write(Server $server, string $slug, array $fields, ?ConsoleEmitter $emitter, string $label): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $contents = $this->render($slug, $fields);
        $path = $this->pathForSlug($slug);
        $ssh = new SshConnection($server);
        $emit->step('traefik-custom-routes', $label.' → '.$path);
        $tmp = '/tmp/dply-traefik-route-'.bin2hex(random_bytes(4));
        $encoded = base64_encode($contents);
        $ssh->exec(sprintf(
            'printf %s | base64 -d | sudo -n tee %s > /dev/null && sudo -n install -m 0644 -T %s %s && sudo -n rm -f %s',
            escapeshellarg($encoded),
            escapeshellarg($tmp),
            escapeshellarg($tmp),
            escapeshellarg($path),
            escapeshellarg($tmp),
        ), 20);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('Failed to write custom route file.');
        }
        $emit->success('Custom route saved; Traefik file provider will hot-reload.');
    }

    private function pathForSlug(string $slug): string
    {
        return self::DYNAMIC_DIR.'/'.self::FILE_PREFIX.$slug.'.yml';
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '') {
            throw new \InvalidArgumentException('Route slug is required.');
        }

        return $slug;
    }

    /**
     * @param  list<string>|string  $hosts
     * @return list<string>
     */
    private function hostsList(mixed $hosts): array
    {
        if (is_string($hosts)) {
            $hosts = preg_split('/[\s,]+/', trim($hosts)) ?: [];
        }
        if (! is_array($hosts)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($h): string => trim((string) $h), $hosts)));
    }

    /**
     * @param  list<string>|string  $middlewares
     * @return list<string>
     */
    private function middlewaresList(mixed $middlewares): array
    {
        if (is_string($middlewares)) {
            $middlewares = preg_split('/[\s,]+/', trim($middlewares)) ?: [];
        }
        if (! is_array($middlewares)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($m): string => trim((string) $m), $middlewares)));
    }
}
