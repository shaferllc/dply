<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Discover and group allowlisted server config files for the unified editor.
 */
class ServerConfigFileCatalog
{
    public function __construct(
        protected RemoteWebserverConfigService $webserverConfig,
    ) {}

    /**
     * @return array<string, array{label: string, files: list<array{path: string, label: string, size: int, mtime: int|null, group: string, engine?: string}>}>
     */
    public function groupedFiles(Server $server, ?string $scope = null, ?string $search = null): array
    {
        $catalog = (array) config('server_manage.config_file_catalog', []);
        $groups = [];
        $seen = [];

        foreach ($catalog as $groupKey => $groupDef) {
            if (! is_array($groupDef)) {
                continue;
            }

            $label = (string) ($groupDef['label'] ?? ucfirst($groupKey));
            $files = [];

            if (! empty($groupDef['discover_engines'])) {
                foreach ($this->webserverConfig->supportedEngines() as $engine) {
                    if ($scope !== null && $scope !== '' && $scope !== $groupKey && $scope !== $engine) {
                        continue;
                    }

                    try {
                        foreach ($this->webserverConfig->listFiles($server, $engine) as $row) {
                            $path = (string) ($row['path'] ?? '');
                            if ($path === '' || isset($seen[$path])) {
                                continue;
                            }
                            $seen[$path] = true;
                            $files[] = [
                                'path' => $path,
                                'label' => (string) ($row['label'] ?? basename($path)),
                                'size' => (int) ($row['size'] ?? 0),
                                'mtime' => $row['mtime'] ?? null,
                                'group' => $groupKey,
                                'engine' => $engine,
                            ];
                        }
                    } catch (\Throwable) {
                        // Non-fatal — picker stays usable with static entries.
                    }
                }
            } else {
                if ($scope !== null && $scope !== '' && $scope !== $groupKey) {
                    continue;
                }

                foreach ($groupDef['entries'] ?? [] as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $path = (string) ($entry['path'] ?? '');
                    if ($path === '' || isset($seen[$path])) {
                        continue;
                    }
                    $seen[$path] = true;
                    $files[] = [
                        'path' => $path,
                        'label' => (string) ($entry['label'] ?? basename($path)),
                        'size' => 0,
                        'mtime' => null,
                        'group' => $groupKey,
                    ];
                }

                foreach ($groupDef['globs'] ?? [] as $glob) {
                    if (! is_string($glob)) {
                        continue;
                    }
                    foreach ($this->discoverGlob($server, $glob) as $row) {
                        $path = (string) ($row['path'] ?? '');
                        if ($path === '' || isset($seen[$path])) {
                            continue;
                        }
                        $seen[$path] = true;
                        $files[] = array_merge($row, ['group' => $groupKey]);
                    }
                }
            }

            if ($search !== null && $search !== '') {
                $needle = strtolower($search);
                $files = array_values(array_filter(
                    $files,
                    fn (array $f): bool => str_contains(strtolower($f['path']), $needle)
                        || str_contains(strtolower($f['label']), $needle),
                ));
            }

            usort($files, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

            if ($files !== []) {
                $groups[$groupKey] = [
                    'label' => $label,
                    'files' => $files,
                ];
            }
        }

        return $groups;
    }

    /**
     * @return list<array{path: string, label: string, size: int, mtime: int|null, group: string, engine?: string}>
     */
    public function flatFiles(Server $server, ?string $scope = null, ?string $search = null): array
    {
        $flat = [];
        foreach ($this->groupedFiles($server, $scope, $search) as $group) {
            foreach ($group['files'] as $file) {
                $flat[] = $file;
            }
        }

        return $flat;
    }

    public function webserverEngineForPath(string $path): ?string
    {
        $layout = (array) config('server_manage.webserver_config_layout', []);

        foreach ($layout as $engine => $def) {
            if (! is_array($def)) {
                continue;
            }
            $main = (string) ($def['main'] ?? '');
            if ($main !== '' && $path === $main) {
                return (string) $engine;
            }
            $prefix = $this->enginePathPrefix((string) $engine);
            if ($prefix !== null && str_starts_with($path, $prefix)) {
                return (string) $engine;
            }
        }

        return null;
    }

    public function fileTypeForPath(string $path): string
    {
        $catalog = (array) config('server_manage.config_file_catalog', []);

        foreach ($catalog as $groupKey => $groupDef) {
            if (! is_array($groupDef)) {
                continue;
            }
            if (! empty($groupDef['discover_engines']) && $this->webserverEngineForPath($path) !== null) {
                return (string) ($groupDef['file_type'] ?? 'nginx');
            }
            foreach ($groupDef['entries'] ?? [] as $entry) {
                if (is_array($entry) && ($entry['path'] ?? '') === $path) {
                    return (string) ($entry['file_type'] ?? $groupKey);
                }
            }
        }

        if (str_ends_with($path, '.ini')) {
            return 'ini';
        }
        if (str_ends_with($path, '.conf')) {
            return 'conf';
        }

        return 'conf';
    }

    /**
     * @return list<array{label: string, type: string, detail?: string}>
     */
    public function autocompleteForPath(string $path): array
    {
        $type = $this->fileTypeForPath($path);
        $snippets = (array) config('server_manage.config_autocomplete_snippets', []);
        $items = $snippets[$type] ?? $snippets['default'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = (string) ($item['label'] ?? '');
            $insert = (string) ($item['insert'] ?? '');
            if ($label === '' || $insert === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'type' => (string) ($item['type'] ?? 'snippet'),
                'detail' => isset($item['detail']) ? (string) $item['detail'] : null,
                'insert' => $insert,
            ];
        }

        return $out;
    }

    private function enginePathPrefix(string $engine): ?string
    {
        return match ($engine) {
            'nginx' => '/etc/nginx/',
            'caddy' => '/etc/caddy/',
            'apache' => '/etc/apache2/',
            'openlitespeed' => '/usr/local/lsws/conf/',
            'traefik' => '/etc/traefik/',
            'haproxy' => '/etc/haproxy/',
            default => null,
        };
    }

    /**
     * @return list<array{path: string, label: string, size: int, mtime: int|null}>
     */
    private function discoverGlob(Server $server, string $glob): array
    {
        if (! $this->globIsAllowlisted($glob)) {
            return [];
        }

        $script = sprintf(
            '{ for f in %s; do [ -e "$f" ] && stat -c "%%n|%%s|%%Y" "$f"; done; } 2>/dev/null || true',
            $glob,
        );

        try {
            $output = app(ServerManageSshExecutor::class)->runInlineBash(
                $server,
                'server-config:catalog-glob',
                $script,
                10,
                function (): void {},
            );
            $buffer = ServerManageSshExecutor::stripSshClientNoise($output->getBuffer());
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\R+/', trim($buffer)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$path, $size, $mtime] = array_pad(explode('|', $line, 3), 3, '');
            if (! $this->pathIsAllowed($path)) {
                continue;
            }
            $rows[] = [
                'path' => $path,
                'label' => basename($path),
                'size' => (int) $size,
                'mtime' => $mtime === '' ? null : (int) $mtime,
            ];
        }

        return $rows;
    }

    private function globIsAllowlisted(string $glob): bool
    {
        $sample = preg_replace('/\*+/', 'x', $glob) ?? $glob;

        return $this->pathIsAllowed(dirname($sample).'/placeholder');
    }

    private function pathIsAllowed(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '/../')) {
            return false;
        }
        if ($path[0] !== '/') {
            return false;
        }
        $exact = (array) config('server_manage.allowed_config_paths_exact', []);
        if (in_array($path, $exact, true)) {
            return true;
        }
        foreach ((array) config('server_manage.allowed_config_path_prefixes', []) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
