<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Inventory + custom-build management for Caddy modules.
 *
 * Standard modules ship in the apt binary; community plugins require
 * rebuilding Caddy with xcaddy (`--with github.com/org/module`).
 *
 * @see https://caddyserver.com/docs/modules
 * @see https://caddyserver.com/docs/build
 */
class CaddyModulesManager
{
    /**
     * @return array{
     *     modules: list<array{id: string, namespace: string, kind: string}>,
     *     plugins: list<array{path: string, version: string, label: string}>,
     *     caddy_version: ?string,
     *     custom_binary: bool,
     *     unreadable: bool,
     * }
     */
    /** @return array<string, mixed> */
    public function read(Server $server, ?ConsoleEmitter $emitter = null): array
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $emit->step('caddy-modules', 'Reading installed modules (`caddy list-modules`)');

        $modules = [];
        $caddyVersion = null;
        $unreadable = true;

        try {
            $ssh = new SshConnection($server);
            $versionOut = trim($ssh->exec('(sudo -n caddy version 2>/dev/null || caddy version 2>/dev/null)', 10));
            if ($versionOut !== '') {
                $caddyVersion = preg_match('/v?\d+\.\d+\.\d+/', $versionOut, $vm) === 1 ? $vm[0] : trim($versionOut);
            }

            $output = trim($ssh->exec('(sudo -n caddy list-modules 2>/dev/null || caddy list-modules 2>/dev/null)', 30));
            if ($output !== '' && ($ssh->lastExecExitCode() ?? 1) === 0) {
                $modules = $this->parseModuleIds($output);
                $unreadable = false;
                $emit->info('Found '.count($modules).' compiled module(s).');
            } else {
                $emit->error('Could not run `caddy list-modules`.');
            }
        } catch (\Throwable $e) {
            $emit->error('SSH failed: '.$e->getMessage());
        }

        $plugins = $this->enrichedManifestPlugins($server, $modules);
        $customBinary = $plugins !== [] || (bool) data_get($server->meta, 'caddy_modules.custom_binary', false);

        if (! $unreadable) {
            $emit->success('Module inventory ready.');
        }

        return [
            'modules' => $modules,
            'plugins' => $plugins,
            'caddy_version' => is_string($caddyVersion) && $caddyVersion !== '' ? $caddyVersion : null,
            'custom_binary' => $customBinary,
            'unreadable' => $unreadable,
        ];
    }

    /**
     * @return list<array{path: string, version: string, label: string}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, string>>
     */
    public function manifestPlugins(Server $server): array
    {
        $raw = data_get($server->meta, 'caddy_modules.plugins', []);
        if (! is_array($raw)) {
            return [];
        }

        $catalog = (array) config('caddy_modules.catalog', []);
        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '' || ! $this->isValidPluginSpec($path)) {
                continue;
            }
            $version = trim((string) ($entry['version'] ?? ''));
            $out[] = [
                'path' => $path,
                'version' => $version,
                'label' => (string) ($catalog[$path]['label'] ?? $path),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $installedModules
     * @return list<array<string, string>>
     *     path: string,
     *     version: string,
     *     label: string,
     *     description: string,
     *     repo: string,
     *     docs_url: string,
     *     module_ids: list<string>,
     *     compiled: bool,
     * }>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, mixed>>
     * @param  array<string, mixed> $installedModules
     */
    public function enrichedManifestPlugins(Server $server, array $installedModules = []): array
    {
        $installedModuleIds = array_map(
            fn (array $module): string => (string) ($module['id'] ?? ''),
            $installedModules,
        );

        $enriched = [];

        foreach ($this->manifestPlugins($server) as $plugin) {
            try {
                $info = $this->packageInfoForInstall($plugin['path']);
            } catch (\Throwable) {
                $info = [
                    'path' => $plugin['path'],
                    'label' => $plugin['label'],
                    'description' => (string) ($this->catalogEntry($plugin['path'])['description'] ?? ''),
                    'repo' => '',
                    'docs_url' => 'https://caddyserver.com/docs/modules',
                    'module_ids' => [],
                ];
            }

            $moduleIds = array_values(array_unique($info['module_ids'] ?? []));

            $enriched[] = [
                'path' => $plugin['path'],
                'version' => $plugin['version'],
                'label' => (string) ($info['label'] ?? $plugin['label']),
                'description' => (string) ($info['description'] ?? ''),
                'repo' => (string) ($info['repo'] ?? ''),
                'docs_url' => (string) ($info['docs_url'] ?? ''),
                'module_ids' => $moduleIds,
                'compiled' => $this->isPluginCompiled($plugin['path'], $moduleIds, $installedModuleIds),
            ];
        }

        return $enriched;
    }

    /**
     * @param  array<string, mixed> $moduleIds
     * @param  array<string, mixed> $installedModules
     * @param  array<string, mixed> $installedModuleIds
     */
    public function isPluginCompiled(string $path, array $moduleIds, array $installedModuleIds): bool
    {
        foreach ($moduleIds as $moduleId) {
            if (in_array($moduleId, $installedModuleIds, true)) {
                return true;
            }
        }

        if ($installedModuleIds === []) {
            return false;
        }

        try {
            return in_array($path, app(CaddyModuleRegistry::class)->packagesFromModuleIds($installedModuleIds), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function addPlugin(Server $server, string $path, string $version = ''): Server
    {
        $path = $this->normalizePluginPath($path);
        $version = trim($version);
        if (! $this->isValidPluginSpec($path)) {
            throw new \InvalidArgumentException(__('Invalid module path. Use a repository import path like github.com/caddy-dns/cloudflare.'));
        }
        if ($version !== '' && ! preg_match('/^[A-Za-z0-9._\-\/]+$/', $version)) {
            throw new \InvalidArgumentException(__('Invalid version pin.'));
        }

        $plugins = $this->manifestPlugins($server);
        foreach ($plugins as $plugin) {
            if ($plugin['path'] === $path) {
                throw new \RuntimeException(__('That plugin is already in the build manifest.'));
            }
        }

        $max = (int) config('caddy_modules.max_plugins', 12);
        if (count($plugins) >= $max) {
            throw new \RuntimeException(__('At most :max custom plugins can be compiled into one Caddy binary.', ['max' => $max]));
        }

        $plugins[] = [
            'path' => $path,
            'version' => $version,
            'label' => (string) ($this->catalogEntry($path)['label'] ?? $path),
        ];

        return $this->persistManifest($server, $plugins);
    }

    /**
     * @throws \RuntimeException
     */
    public function removePlugin(Server $server, string $path): Server
    {
        $path = $this->normalizePluginPath($path);
        $plugins = array_values(array_filter(
            $this->manifestPlugins($server),
            fn (array $plugin): bool => $plugin['path'] !== $path,
        ));

        if (count($plugins) === count($this->manifestPlugins($server))) {
            throw new \RuntimeException(__('Plugin not found in the build manifest.'));
        }

        return $this->persistManifest($server, $plugins);
    }

    public function clearManifest(Server $server): Server
    {
        return $this->persistManifest($server, [], customBinary: false);
    }

    /**
     * Bash script queued over SSH to compile + install a custom Caddy binary.
     */
    public function rebuildScript(Server $server): string
    {
        $plugins = $this->manifestPlugins($server);
        $withLines = '';
        foreach ($plugins as $plugin) {
            $spec = $plugin['path'];
            if ($plugin['version'] !== '') {
                $spec .= '@'.$plugin['version'];
            }
            $withLines .= 'BUILD_CMD="$BUILD_CMD --with '.escapeshellarg($spec)."\"\n";
        }

        $pluginCount = count($plugins);

        return <<<BASH
set -euo pipefail

echo "[dply] Ensuring Go toolchain…"
if ! command -v go >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y golang-go
fi
export PATH="\${PATH}:\$(go env GOPATH 2>/dev/null)/bin:/root/go/bin"

if ! command -v xcaddy >/dev/null 2>&1; then
  echo "[dply] Installing xcaddy…"
  go install github.com/caddyserver/xcaddy/cmd/xcaddy@latest
fi

CADDY_VER=""
if command -v caddy >/dev/null 2>&1; then
  CADDY_VER=\$(caddy version 2>/dev/null | grep -Eo 'v?[0-9]+\\.[0-9]+\\.[0-9]+' | head -1 || true)
fi
if [ -z "\$CADDY_VER" ]; then
  echo "[dply] Could not detect installed Caddy version — building latest." >&2
else
  echo "[dply] Building Caddy \$CADDY_VER with {$pluginCount} plugin(s)…"
fi

TMP="/tmp/dply-caddy-build.\$\$"
BUILD_CMD="xcaddy build"
if [ -n "\$CADDY_VER" ]; then
  BUILD_CMD="\$BUILD_CMD \$CADDY_VER"
fi
{$withLines}\$BUILD_CMD -o "\$TMP"

echo "[dply] Validating new binary against /etc/caddy/Caddyfile…"
"\$TMP" validate --config /etc/caddy/Caddyfile

BAK="/usr/bin/caddy.dply-bak.\$(date +%Y%m%d%H%M%S)"
if [ -x /usr/bin/caddy ]; then
  cp -a /usr/bin/caddy "\$BAK"
  echo "[dply] Backed up previous binary to \$BAK"
fi

install -m 755 "\$TMP" /usr/bin/caddy
rm -f "\$TMP"

echo "[dply] Restarting Caddy…"
if systemctl is-active --quiet caddy 2>/dev/null; then
  systemctl restart caddy
else
  service caddy restart || true
fi

caddy version
echo "[dply] Sample of compiled modules:"
caddy list-modules 2>/dev/null | head -30
BASH;
    }

    /**
     * Restore the distro package binary (drops custom xcaddy build).
     */
    public function restorePackageScript(): string
    {
        return <<<'BASH'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
echo "[dply] Reinstalling Caddy from apt…"
apt-get update -qq
apt-get install --reinstall -y caddy
echo "[dply] Restarting Caddy…"
systemctl restart caddy || service caddy restart || true
caddy version
caddy list-modules 2>/dev/null | head -20
BASH;
    }

    /**
     * @return list<array{id: string, namespace: string, kind: string}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, string>>
     */
    public function parseModuleIds(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $id = trim($line);
            if ($id === '' || str_starts_with($id, '#')) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'namespace' => $this->namespaceFor($id),
                'kind' => $this->kindFor($id),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $rows;
    }

    public function isValidPluginSpec(string $path): bool
    {
        $path = trim($path);

        return $path !== ''
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\-\/]*(?:@[A-Za-z0-9._\-]+)?$/', $path) === 1
            && ! str_contains($path, '..');
    }

    /**
     * @param  array<string, mixed> $manifestPlugins
     * @param  array<string, mixed> $installedModules
     * @return list<array<string, string>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<mixed>
     * @param  array<string, mixed> $installedModules
     * @param  array<string, mixed> $manifestPlugins
     */
    public function satisfiedPluginPaths(array $manifestPlugins, array $installedModules): array
    {
        $paths = array_map(
            fn (array $plugin): string => $plugin['path'],
            $manifestPlugins,
        );

        $moduleIds = array_map(
            fn (array $module): string => $module['id'],
            $installedModules,
        );

        try {
            $paths = array_merge($paths, app(CaddyModuleRegistry::class)->packagesFromModuleIds($moduleIds));
        } catch (\Throwable) {
            // Registry unavailable — manifest-only filtering still works.
        }

        return array_values(array_unique(array_filter($paths, fn (string $path): bool => $path !== '')));
    }

    /**
     * @param  array<string, mixed> $manifestPlugins
     * @param  array<string, mixed> $installedModules
     * @return list<mixed>
     */
    /** @return array<string, mixed> */
    public function availableCatalog(array $manifestPlugins, array $installedModules): array
    {
        $satisfied = array_flip($this->satisfiedPluginPaths($manifestPlugins, $installedModules));
        $catalog = (array) config('caddy_modules.catalog', []);

        return array_filter(
            $catalog,
            fn (array $meta, string $path): bool => ! isset($satisfied[$path]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<string, mixed> $manifestPlugins
     * @param  array<string, mixed> $installedModules
     * @return list<array{
     *     path: string,
     *     repo: string,
     *     label: string,
     *     description: string,
     *     module_ids: list<string>,
     * }>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<mixed>
     * @param  array<string, mixed> $installedModules
     * @param  array<string, mixed> $manifestPlugins
     */
    public function browsePackages(array $manifestPlugins, array $installedModules, string $search = ''): array
    {
        $satisfied = array_flip($this->satisfiedPluginPaths($manifestPlugins, $installedModules));
        $search = strtolower(trim($search));
        $packages = app(CaddyModuleRegistry::class)->communityPackages();
        $out = [];

        foreach ($packages as $package) {
            if (isset($satisfied[$package['path']])) {
                continue;
            }

            if ($search !== '') {
                $haystack = strtolower(implode(' ', [
                    $package['path'],
                    $package['label'],
                    $package['description'],
                    implode(' ', $package['module_ids']),
                ]));

                if (! str_contains($haystack, $search)) {
                    continue;
                }
            }

            $out[] = $package;
        }

        return $out;
    }

    /**
     * @return list<mixed>
     *     path: string,
     *     repo: string,
     *     label: string,
     *     description: string,
     *     module_ids: list<string>,
     *     docs_url: string,
     * @param  array<string, mixed> $installedModules
     * @param  array<string, mixed> $manifestPlugins
     * }
     */
    /** @return array<string, mixed> */
    public function packageInfoForInstall(string $path): array
    {
        $path = trim($path);
        if (! $this->isValidPluginSpec($path)) {
            throw new \InvalidArgumentException(__('Invalid module path. Use a repository import path like github.com/caddy-dns/cloudflare.'));
        }

        try {
            $info = app(CaddyModuleRegistry::class)->packageInfo($path);
            if ($info !== null) {
                return $info;
            }
        } catch (\Throwable) {
            // Fall back to catalog / generic copy when the registry is unavailable.
        }

        $catalog = $this->catalogEntry($path);

        return [
            'path' => $path,
            'repo' => '',
            'label' => (string) ($catalog['label'] ?? str_replace(['-', '_'], ' ', basename($path))),
            'description' => (string) ($catalog['description'] ?? __('Community plugin — confirm the import path matches the module repository before rebuilding.')),
            'module_ids' => [],
            'docs_url' => 'https://caddyserver.com/docs/modules',
        ];
    }

    private function normalizePluginPath(string $path): string
    {
        $path = trim($path);
        if (str_contains($path, '@')) {
            [$path, $version] = explode('@', $path, 2);
            $path = trim($path);
        }

        return $path;
    }

    /**
     * @return array{label?: string, description?: string}
     */
    private function catalogEntry(string $path): array
    {
        $catalog = (array) config('caddy_modules.catalog', []);

        return (array) ($catalog[$path] ?? []);
    }

    /**
     * @param  array<string, mixed> $plugins
     */
    private function persistManifest(Server $server, array $plugins, ?bool $customBinary = null): Server
    {
        $meta = $server->meta ?? [];
        $stored = [];
        foreach ($plugins as $plugin) {
            $row = ['path' => $plugin['path']];
            if ($plugin['version'] !== '') {
                $row['version'] = $plugin['version'];
            }
            $stored[] = $row;
        }

        $meta['caddy_modules'] = [
            'plugins' => $stored,
            'custom_binary' => $customBinary ?? ($stored !== []),
            'updated_at' => now()->toIso8601String(),
        ];

        $server->forceFill(['meta' => $meta])->save();

        return $server->fresh() ?? $server;
    }

    private function namespaceFor(string $id): string
    {
        $parts = explode('.', $id);
        if (count($parts) >= 2) {
            return $parts[0].'.'.$parts[1];
        }

        return $parts[0] ?? 'other';
    }

    private function kindFor(string $id): string
    {
        if (str_contains($id, '.handlers.')) {
            return 'handlers';
        }
        if (str_contains($id, '.matchers.')) {
            return 'matchers';
        }
        if (str_starts_with($id, 'tls.') || str_contains($id, '.tls.')) {
            return 'tls';
        }
        if (str_contains($id, '.storage.') || str_contains($id, 'storage.')) {
            return 'storage';
        }
        if (str_contains($id, 'dns.') || str_contains($id, '.dns.')) {
            return 'dns';
        }
        if (str_contains($id, 'caddy.config.') || str_starts_with($id, 'admin.') || str_starts_with($id, 'events.')) {
            return 'core';
        }

        return 'other';
    }
}
