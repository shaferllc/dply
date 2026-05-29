<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Support\Servers\ServerInstalledServices;

/**
 * Discover and group allowlisted server config files for the unified editor.
 */
class ServerConfigFileCatalog
{
    public function __construct(
        protected RemoteWebserverConfigService $webserverConfig,
        protected ServerManageSshExecutor $executor,
    ) {}

    /**
     * @return array<string, array{label: string, files: list<array{path: string, label: string, size: int, mtime: int|null, group: string, engine?: string}>}>
     */
    public function groupedFiles(Server $server, ?string $scope = null, ?string $search = null): array
    {
        $catalog = (array) config('server_manage.config_file_catalog', []);
        $probes = $this->collectDiscoveryProbes($server, $scope);
        $probeResults = $this->discoverProbeResults($server, $probes);

        $groups = [];
        $seen = [];

        foreach ($catalog as $groupKey => $groupDef) {
            if (! is_array($groupDef)) {
                continue;
            }

            $label = (string) ($groupDef['label'] ?? ucfirst($groupKey));
            $files = [];

            if (! empty($groupDef['discover_engines'])) {
                foreach ($probes as $index => $probe) {
                    if (($probe['kind'] ?? '') !== 'engine' || ($probe['group'] ?? '') !== $groupKey) {
                        continue;
                    }

                    $engine = (string) ($probe['engine'] ?? '');
                    if ($engine === '') {
                        continue;
                    }

                    foreach ($this->formatEngineProbeFiles($probeResults[$index] ?? [], $engine) as $row) {
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

                foreach ($probes as $index => $probe) {
                    if (($probe['kind'] ?? '') !== 'glob' || ($probe['group'] ?? '') !== $groupKey) {
                        continue;
                    }

                    foreach ($probeResults[$index] ?? [] as $row) {
                        $path = (string) ($row['path'] ?? '');
                        if ($path === '' || isset($seen[$path])) {
                            continue;
                        }
                        $seen[$path] = true;
                        $files[] = [
                            'path' => $path,
                            'label' => basename($path),
                            'size' => (int) ($row['size'] ?? 0),
                            'mtime' => $row['mtime'] ?? null,
                            'group' => $groupKey,
                        ];
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

    /**
     * @return list<array{kind: string, group: string, engine?: string, patterns: list<string>}>
     */
    private function collectDiscoveryProbes(Server $server, ?string $scope): array
    {
        $catalog = (array) config('server_manage.config_file_catalog', []);
        $layout = (array) config('server_manage.webserver_config_layout', []);
        $probes = [];

        foreach ($catalog as $groupKey => $groupDef) {
            if (! is_array($groupDef)) {
                continue;
            }

            if (! empty($groupDef['discover_engines'])) {
                foreach ($this->enginesForDiscovery($server) as $engine) {
                    if ($scope !== null && $scope !== '' && $scope !== $groupKey && $scope !== $engine) {
                        continue;
                    }

                    $engineLayout = $layout[$engine] ?? null;
                    if (! is_array($engineLayout)) {
                        continue;
                    }

                    $patterns = array_values(array_filter(array_merge(
                        [(string) ($engineLayout['main'] ?? '')],
                        array_filter((array) ($engineLayout['globs'] ?? []), is_string(...)),
                    )));

                    if ($patterns === []) {
                        continue;
                    }

                    $probes[] = [
                        'kind' => 'engine',
                        'group' => $groupKey,
                        'engine' => $engine,
                        'patterns' => $patterns,
                    ];
                }

                continue;
            }

            if ($scope !== null && $scope !== '' && $scope !== $groupKey) {
                continue;
            }

            foreach ($groupDef['globs'] ?? [] as $glob) {
                if (! is_string($glob) || ! $this->globIsAllowlisted($glob)) {
                    continue;
                }

                $probes[] = [
                    'kind' => 'glob',
                    'group' => $groupKey,
                    'patterns' => [$glob],
                ];
            }
        }

        return $probes;
    }

    /**
     * Run every catalog probe in one SSH exec to avoid repeated TaskRunner
     * connect/scp/run round-trips per engine or glob.
     *
     * @param  list<array{kind: string, group: string, engine?: string, patterns: list<string>}>  $probes
     * @return array<int, list<array{path: string, size: int, mtime: int|null}>>
     */
    private function discoverProbeResults(Server $server, array $probes): array
    {
        if ($probes === []) {
            return [];
        }

        $script = $this->buildBatchDiscoveryScript($probes);

        try {
            $output = $this->executor->runInlineBash(
                $server,
                'server-config:catalog-batch',
                $script,
                30,
                function (): void {},
            );
            $buffer = ServerManageSshExecutor::stripSshClientNoise($output->getBuffer());
        } catch (\Throwable) {
            return [];
        }

        return $this->parseBatchDiscoveryOutput($buffer, count($probes));
    }

    /**
     * @param  list<array{kind: string, group: string, engine?: string, patterns: list<string>}>  $probes
     */
    private function buildBatchDiscoveryScript(array $probes): string
    {
        $parts = [];

        foreach ($probes as $index => $probe) {
            $parts[] = sprintf('echo __DPLY_PROBE_%d__', $index);

            foreach ($probe['patterns'] as $pattern) {
                $parts[] = sprintf(
                    'for f in %s; do [ -e "$f" ] && stat -c "%%n|%%s|%%Y" "$f"; done',
                    $pattern,
                );
            }
        }

        if ($parts === []) {
            return 'true';
        }

        return '{ '.implode('; ', $parts).' } 2>/dev/null || true';
    }

    /**
     * @return array<int, list<array{path: string, size: int, mtime: int|null}>>
     */
    private function parseBatchDiscoveryOutput(string $buffer, int $probeCount): array
    {
        $results = array_fill(0, $probeCount, []);
        $currentProbe = null;

        foreach (preg_split('/\R+/', trim($buffer)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match('/^__DPLY_PROBE_(\d+)__$/', $line, $matches) === 1) {
                $currentProbe = (int) $matches[1];

                continue;
            }

            if ($currentProbe === null || ! isset($results[$currentProbe])) {
                continue;
            }

            [$path, $size, $mtime] = array_pad(explode('|', $line, 3), 3, '');
            if (! $this->pathIsAllowed($path)) {
                continue;
            }

            $results[$currentProbe][] = [
                'path' => $path,
                'size' => (int) $size,
                'mtime' => $mtime === '' ? null : (int) $mtime,
            ];
        }

        return $results;
    }

    /**
     * @param  list<array{path: string, size: int, mtime: int|null}>  $rows
     * @return list<array{path: string, label: string, size: int, mtime: int|null}>
     */
    private function formatEngineProbeFiles(array $rows, string $engine): array
    {
        $layout = (array) config('server_manage.webserver_config_layout', []);
        $engineLayout = $layout[$engine] ?? null;
        if (! is_array($engineLayout)) {
            return [];
        }

        $main = (string) ($engineLayout['main'] ?? '');
        $byPath = [];

        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $byPath[$path] = [
                'path' => $path,
                'label' => $path === $main ? __('main config').' — '.basename($path) : basename($path),
                'size' => (int) ($row['size'] ?? 0),
                'mtime' => $row['mtime'] ?? null,
            ];
        }

        $out = [];
        if ($main !== '' && isset($byPath[$main])) {
            $out[] = $byPath[$main];
            unset($byPath[$main]);
        }

        ksort($byPath);

        return array_merge($out, array_values($byPath));
    }

    /**
     * Only probe webserver engines that the stack summary says are installed.
     * When the stack is unknown (fresh import / still provisioning), fail open
     * and probe every supported engine so the picker isn't empty.
     *
     * @return list<string>
     */
    private function enginesForDiscovery(Server $server): array
    {
        $engines = $this->webserverConfig->supportedEngines();
        $installed = ServerInstalledServices::tagsFor($server);

        if (array_key_exists('unknown', $installed)) {
            return $engines;
        }

        return array_values(array_filter(
            $engines,
            fn (string $engine): bool => array_key_exists($engine, $installed),
        ));
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
