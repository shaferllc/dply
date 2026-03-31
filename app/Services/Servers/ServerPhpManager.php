<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Facades\Cache;

class ServerPhpManager
{
    /**
     * @return list<array{id: string, label: string}>
     */
    public function supportedVersions(Server $server): array
    {
        $role = $this->serverRole($server);
        $versions = [];

        foreach ((array) config('server_provision_options.php_versions', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $this->normalizeVersionId($row['id'] ?? null);
            $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : null;

            if ($id === null || $id === 'none' || $label === null || ! $this->rowMatchesRole($row, $role)) {
                continue;
            }

            $versions[] = [
                'id' => $id,
                'label' => $label,
            ];
        }

        return array_values($versions);
    }

    /**
     * @return array{
     *     is_supported_environment: bool|null,
     *     installed_versions: list<array{id: string, label: string, is_supported: bool, site_count: int}>,
     *     detected_default_version: ?string
     * }
     */
    public function cachedInventory(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $inventory = is_array($meta['php_inventory'] ?? null) ? $meta['php_inventory'] : [];
        $supportedVersions = $this->supportedVersions($server);
        $supportedMap = [];

        foreach ($supportedVersions as $version) {
            $supportedMap[$version['id']] = $version['label'];
        }

        $siteCounts = $server->sites()
            ->selectRaw('php_version, COUNT(*) as aggregate')
            ->whereNotNull('php_version')
            ->groupBy('php_version')
            ->pluck('aggregate', 'php_version')
            ->all();

        $installedIds = $this->normalizeVersionList($inventory['installed_versions'] ?? []);
        $installedVersions = [];

        foreach ($installedIds as $id) {
            $installedVersions[] = [
                'id' => $id,
                'label' => $supportedMap[$id] ?? 'PHP '.$id,
                'is_supported' => array_key_exists($id, $supportedMap),
                'site_count' => (int) ($siteCounts[$id] ?? 0),
            ];
        }

        return [
            'is_supported_environment' => array_key_exists('supported', $inventory) ? (bool) $inventory['supported'] : null,
            'installed_versions' => $installedVersions,
            'detected_default_version' => $this->normalizeVersionId($inventory['detected_default_version'] ?? null),
        ];
    }

    /**
     * @return array{cli_default: ?string, new_site_default: ?string}
     */
    public function currentDefaults(Server $server, ?array $inventory = null): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $inventory ??= $this->cachedInventory($server);
        $installedIds = array_column($inventory['installed_versions'], 'id');
        $cliDefault = $this->normalizeVersionId($meta['default_php_version'] ?? null);
        if ($cliDefault === null || ! in_array($cliDefault, $installedIds, true)) {
            $cliDefault = $inventory['detected_default_version'];
        }
        $newSiteDefault = $this->normalizeVersionId($meta['php_new_site_default_version'] ?? null);

        if ($newSiteDefault === null || ! in_array($newSiteDefault, $installedIds, true)) {
            $newSiteDefault = $cliDefault;
        }

        return [
            'cli_default' => $cliDefault,
            'new_site_default' => $newSiteDefault,
        ];
    }

    /**
     * @param  array{installed_versions?: mixed, detected_default_version?: mixed, supported?: mixed}  $freshInventory
     * @return array<string, mixed>
     */
    public function reconcileFreshInventory(Server $server, array $freshInventory): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $installedIds = $this->normalizeVersionList($freshInventory['installed_versions'] ?? []);
        $detectedDefault = $this->normalizeVersionId($freshInventory['detected_default_version'] ?? null);

        if ($detectedDefault === null || ! in_array($detectedDefault, $installedIds, true)) {
            $detectedDefault = $installedIds[0] ?? null;
        }

        $newSiteDefault = $this->normalizeVersionId($meta['php_new_site_default_version'] ?? null);
        if ($newSiteDefault === null || ! in_array($newSiteDefault, $installedIds, true)) {
            $newSiteDefault = $detectedDefault;
        }

        $meta['php_inventory'] = [
            'supported' => array_key_exists('supported', $freshInventory) ? (bool) $freshInventory['supported'] : true,
            'installed_versions' => $installedIds,
            'detected_default_version' => $detectedDefault,
        ];
        $meta['default_php_version'] = $detectedDefault;
        $meta['php_new_site_default_version'] = $newSiteDefault;

        return $meta;
    }

    /**
     * @return array{
     *     summary: array{
     *         supported_versions: list<array{id: string, label: string}>,
     *         installed_versions: list<array{id: string, label: string, is_supported: bool, site_count: int}>,
     *         installed_count: int,
     *         cli_default: ?string,
     *         new_site_default: ?string,
     *         detected_default_version: ?string
     *     },
     *     version_rows: list<array{id: string, label: string, is_supported: bool, is_installed: bool, site_count: int}>
     * }
     */
    public function workspaceData(Server $server): array
    {
        $supportedVersions = $this->supportedVersions($server);
        $inventory = $this->cachedInventory($server);
        $defaults = $this->currentDefaults($server, $inventory);
        $rows = [];

        foreach ($supportedVersions as $version) {
            $rows[$version['id']] = [
                'id' => $version['id'],
                'label' => $version['label'],
                'is_supported' => true,
                'is_installed' => false,
                'site_count' => 0,
            ];
        }

        foreach ($inventory['installed_versions'] as $version) {
            $rows[$version['id']] = [
                'id' => $version['id'],
                'label' => $version['label'],
                'is_supported' => $version['is_supported'],
                'is_installed' => true,
                'site_count' => $version['site_count'],
            ];
        }

        return [
            'summary' => [
                'supported_versions' => $supportedVersions,
                'installed_versions' => $inventory['installed_versions'],
                'installed_count' => count($inventory['installed_versions']),
                'is_supported_environment' => $inventory['is_supported_environment'],
                'cli_default' => $defaults['cli_default'],
                'new_site_default' => $defaults['new_site_default'],
                'detected_default_version' => $inventory['detected_default_version'],
            ],
            'version_rows' => array_values($rows),
        ];
    }

    /**
     * @return array{
     *     current_version: ?string,
     *     current_version_label: string,
     *     installed_versions: list<array{id: string, label: string, is_supported: bool}>,
     *     selected_version_installed: bool,
     *     has_installed_versions: bool,
     *     mismatch_version: ?string,
     *     server_php_workspace_url: string,
     *     runtime: array{memory_limit: ?string, upload_max_filesize: ?string, max_execution_time: ?string},
     *     opcache: array{status: string, summary: string},
     *     composer_auth: array{summary: string},
     *     extensions: array{summary: string}
     * }
     */
    public function sitePhpData(Server $server, Site $site): array
    {
        $inventory = $this->cachedInventory($server);
        $runtime = is_array($site->meta['php_runtime'] ?? null) ? $site->meta['php_runtime'] : [];
        $currentVersion = $this->normalizeVersionId($site->php_version);
        $installedVersions = array_map(
            fn (array $version): array => [
                'id' => $version['id'],
                'label' => $version['label'],
                'is_supported' => $version['is_supported'],
            ],
            $inventory['installed_versions']
        );
        $installedIds = array_column($installedVersions, 'id');
        $selectedVersionInstalled = $currentVersion !== null && in_array($currentVersion, $installedIds, true);

        return [
            'current_version' => $currentVersion,
            'current_version_label' => $currentVersion ? 'PHP '.$currentVersion : __('Not set'),
            'installed_versions' => $installedVersions,
            'selected_version_installed' => $selectedVersionInstalled,
            'has_installed_versions' => $installedVersions !== [],
            'mismatch_version' => $currentVersion !== null && ! $selectedVersionInstalled ? $currentVersion : null,
            'server_php_workspace_url' => route('servers.php', $server, false),
            'runtime' => [
                'memory_limit' => is_string($runtime['memory_limit'] ?? null) ? $runtime['memory_limit'] : null,
                'upload_max_filesize' => is_string($runtime['upload_max_filesize'] ?? null) ? $runtime['upload_max_filesize'] : null,
                'max_execution_time' => isset($runtime['max_execution_time']) ? (string) $runtime['max_execution_time'] : null,
            ],
            'opcache' => [
                'status' => 'unknown',
                'summary' => __('Server-level OPcache status is managed from the server PHP workspace.'),
            ],
            'composer_auth' => [
                'summary' => __('Open the server PHP workspace to manage shared Composer authentication for this server.'),
            ],
            'extensions' => [
                'summary' => __('Open the server PHP workspace to review installed versions and shared extension entry points.'),
            ],
        ];
    }

    /**
     * @return array{
     *     available_versions: list<array{id: string, label: string}>,
     *     preselected_version: string
     * }
     */
    public function siteCreationPhpData(Server $server): array
    {
        $inventory = $this->cachedInventory($server);
        $availableVersions = array_map(
            fn (array $version): array => [
                'id' => $version['id'],
                'label' => $version['label'],
            ],
            array_values(array_filter(
                $inventory['installed_versions'],
                fn (array $version): bool => (bool) ($version['is_supported'] ?? false)
            ))
        );
        $availableVersionIds = array_column($availableVersions, 'id');
        $savedNewSiteDefault = $this->normalizeVersionId(data_get($server->meta, 'php_new_site_default_version'));
        $resolvedDefaults = $this->currentDefaults($server, $inventory);
        $preselectedVersion = '';

        if (
            $savedNewSiteDefault !== null
            && in_array($savedNewSiteDefault, $availableVersionIds, true)
            && $resolvedDefaults['new_site_default'] === $savedNewSiteDefault
        ) {
            $preselectedVersion = $savedNewSiteDefault;
        }

        return [
            'available_versions' => $availableVersions,
            'preselected_version' => $preselectedVersion,
        ];
    }

    /**
     * @return array{status: 'succeeded'|'stale', message: string}
     */
    public function refreshInventory(Server $server): array
    {
        $server->refresh();

        $this->persistRefreshMeta($server, [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'error' => null,
            'failed_at' => null,
            'stale_at' => null,
        ]);

        try {
            $freshInventory = $this->fetchRemoteInventory($server);
        } catch (\Throwable $e) {
            $this->persistRefreshMeta($server, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }

        $meta = $this->reconcileFreshInventory($server->fresh(), $freshInventory);
        $meta['php_inventory_refresh'] = [
            'status' => 'succeeded',
            'started_at' => data_get($server->meta, 'php_inventory_refresh.started_at'),
            'refreshed_at' => now()->toIso8601String(),
            'error' => null,
            'failed_at' => null,
            'stale_at' => null,
        ];

        try {
            $this->persistRefreshedInventoryMeta($server, $meta);

            return [
                'status' => 'succeeded',
                'message' => __('PHP inventory refreshed.'),
            ];
        } catch (\Throwable $e) {
            $this->persistRefreshMeta($server->fresh(), [
                'status' => 'stale',
                'error' => $e->getMessage(),
                'stale_at' => now()->toIso8601String(),
            ]);

            return [
                'status' => 'stale',
                'message' => __('Remote PHP state changed, but Dply could not save the refreshed snapshot.'),
            ];
        }
    }

    /**
     * @return array{status: 'succeeded'|'stale', message: string}
     */
    public function applyPackageAction(Server $server, string $action, string $version): array
    {
        $version = $this->normalizeVersionId($version) ?? '';
        $action = trim($action);

        if ($version === '' || $action === '') {
            throw new \RuntimeException('PHP action and version are required.');
        }

        $lock = Cache::lock($this->packageActionLockKey($server), $this->packageActionLockSeconds($action));

        if (! $lock->get()) {
            throw new \RuntimeException('Another PHP package action is already running for this server.');
        }

        try {
            $server = $server->fresh();
            if ($server === null || ! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
                throw new \RuntimeException('Provisioning and SSH must be ready before managing PHP packages.');
            }

            $preflightInventory = $this->fetchRemoteInventory($server);
            $this->guardPackageAction($server, $action, $version, $preflightInventory);

            $this->executePackageAction($server, $action, $version);

            $freshInventory = $this->fetchRemoteInventory($server->fresh());
            $meta = $this->reconcileFreshInventory($server->fresh(), $freshInventory);

            if ($action === 'set_new_site_default') {
                $meta['php_new_site_default_version'] = $version;
            }

            $meta['php_inventory_refresh'] = [
                'status' => 'succeeded',
                'started_at' => data_get($server->meta, 'php_inventory_refresh.started_at'),
                'refreshed_at' => now()->toIso8601String(),
                'error' => null,
                'failed_at' => null,
                'stale_at' => null,
            ];

            try {
                $this->persistRefreshedInventoryMeta($server->fresh(), $meta);

                return [
                    'status' => 'succeeded',
                    'message' => $this->packageActionSuccessMessage($action, $version),
                ];
            } catch (\Throwable $e) {
                $this->persistRefreshMeta($server->fresh(), [
                    'status' => 'stale',
                    'error' => $e->getMessage(),
                    'stale_at' => now()->toIso8601String(),
                ]);

                return [
                    'status' => 'stale',
                    'message' => __('Remote PHP state changed, but Dply could not save the refreshed snapshot.'),
                ];
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  mixed  $value
     */
    protected function normalizeVersionId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/(\d+\.\d+)/', $value, $matches) !== 1) {
            return $value === 'none' ? 'none' : null;
        }

        return $matches[1];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    protected function normalizeVersionList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            $version = $this->normalizeVersionId($item);

            if ($version === null || $version === 'none') {
                continue;
            }

            $normalized[$version] = $version;
        }

        return array_values($normalized);
    }

    protected function serverRole(Server $server): string
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $role = $meta['server_role'] ?? 'application';

        return is_string($role) && $role !== '' ? $role : 'application';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function rowMatchesRole(array $row, string $role): bool
    {
        $only = $row['only_server_roles'] ?? null;
        if (is_array($only) && $only !== [] && ! in_array($role, $only, true)) {
            return false;
        }

        $excluded = $row['exclude_server_roles'] ?? null;

        return ! (is_array($excluded) && in_array($role, $excluded, true));
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $inventory
     */
    protected function guardPackageAction(Server $server, string $action, string $version, array $inventory): void
    {
        $supportedIds = array_column($this->supportedVersions($server), 'id');
        $installedIds = $this->normalizeVersionList($inventory['installed_versions'] ?? []);
        $siteCount = (int) $server->sites()->where('php_version', $version)->count();
        $defaults = $this->currentDefaults($server, [
            'installed_versions' => array_map(fn (string $id) => [
                'id' => $id,
                'label' => 'PHP '.$id,
                'is_supported' => in_array($id, $supportedIds, true),
                'site_count' => 0,
            ], $installedIds),
            'detected_default_version' => $this->normalizeVersionId($inventory['detected_default_version'] ?? null),
            'is_supported_environment' => (bool) ($inventory['supported'] ?? true),
        ]);

        if ($action === 'uninstall') {
            $defaults['cli_default'] = $this->normalizeVersionId($inventory['detected_default_version'] ?? null);
        }

        if (! ($inventory['supported'] ?? true)) {
            throw new \RuntimeException('This server does not report a supported PHP package environment.');
        }

        match ($action) {
            'install' => $this->guardInstallAction($version, $supportedIds, $installedIds),
            'set_cli_default' => $this->guardSetCliDefaultAction($version, $installedIds),
            'set_new_site_default' => $this->guardSetNewSiteDefaultAction($version, $installedIds),
            'patch' => $this->guardPatchAction($version, $installedIds),
            'uninstall' => $this->guardUninstallAction($version, $installedIds, $siteCount, $defaults),
            default => throw new \RuntimeException('Unknown PHP package action.'),
        };
    }

    /**
     * @param  list<string>  $supportedIds
     * @param  list<string>  $installedIds
     */
    protected function guardInstallAction(string $version, array $supportedIds, array $installedIds): void
    {
        if (! in_array($version, $supportedIds, true)) {
            throw new \RuntimeException('PHP '.$version.' is not supported on this server.');
        }

        if (in_array($version, $installedIds, true)) {
            throw new \RuntimeException('PHP '.$version.' is already installed.');
        }
    }

    /**
     * @param  list<string>  $installedIds
     */
    protected function guardSetCliDefaultAction(string $version, array $installedIds): void
    {
        if (! in_array($version, $installedIds, true)) {
            throw new \RuntimeException('Install PHP '.$version.' before setting it as the CLI default.');
        }
    }

    /**
     * @param  list<string>  $installedIds
     */
    protected function guardSetNewSiteDefaultAction(string $version, array $installedIds): void
    {
        if (! in_array($version, $installedIds, true)) {
            throw new \RuntimeException('Install PHP '.$version.' before setting it as the new-site default.');
        }
    }

    /**
     * @param  list<string>  $installedIds
     */
    protected function guardPatchAction(string $version, array $installedIds): void
    {
        if (! in_array($version, $installedIds, true)) {
            throw new \RuntimeException('Install PHP '.$version.' before patching it.');
        }
    }

    /**
     * @param  list<string>  $installedIds
     * @param  array{cli_default: ?string, new_site_default: ?string}  $defaults
     */
    protected function guardUninstallAction(string $version, array $installedIds, int $siteCount, array $defaults): void
    {
        if (! in_array($version, $installedIds, true)) {
            throw new \RuntimeException('PHP '.$version.' is not installed.');
        }

        if ($siteCount > 0) {
            throw new \RuntimeException(trans_choice('PHP :version is still used by :count site.|PHP :version is still used by :count sites.', $siteCount, [
                'version' => $version,
                'count' => $siteCount,
            ]));
        }

        if (($defaults['cli_default'] ?? null) === $version) {
            throw new \RuntimeException('PHP '.$version.' is still the CLI default for this server.');
        }

        if (($defaults['new_site_default'] ?? null) === $version) {
            throw new \RuntimeException('PHP '.$version.' is still the default for new PHP sites on this server.');
        }
    }

    /**
     * @return array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}
     */
    protected function fetchRemoteInventory(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
            throw new \RuntimeException('Provisioning and SSH must be ready before refreshing PHP inventory.');
        }

        $supportedIds = array_column($this->supportedVersions($server), 'id');
        $quotedVersions = implode(' ', array_map(static fn (string $id) => escapeshellarg($id), $supportedIds));
        $ssh = new SshConnection($server);

        try {
            $output = $ssh->exec($this->privilegedShellScript($server, $quotedVersions), 120);
        } finally {
            $ssh->disconnect();
        }

        return $this->parseRemoteInventoryOutput($output);
    }

    protected function executePackageAction(Server $server, string $action, string $version): void
    {
        $ssh = new SshConnection($server);

        try {
            $ssh->exec($this->packageActionScript($server, $action, $version), 600);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function persistRefreshedInventoryMeta(Server $server, array $meta): void
    {
        $server->forceFill([
            'meta' => $meta,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $refreshMeta
     */
    protected function persistRefreshMeta(Server $server, array $refreshMeta): void
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $existing = is_array($meta['php_inventory_refresh'] ?? null) ? $meta['php_inventory_refresh'] : [];
        $meta['php_inventory_refresh'] = array_merge($existing, $refreshMeta);

        $server->forceFill([
            'meta' => $meta,
        ])->save();
    }

    /**
     * @return array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}
     */
    protected function parseRemoteInventoryOutput(string $output): array
    {
        $values = [];

        foreach (preg_split("/\r?\n/", trim($output)) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value);
        }

        return [
            'supported' => ($values['supported'] ?? 'false') === 'true',
            'installed_versions' => $this->normalizeVersionList(
                ($values['installed_versions'] ?? '') === ''
                    ? []
                    : explode(',', $values['installed_versions'])
            ),
            'detected_default_version' => $this->normalizeVersionId($values['detected_default_version'] ?? null),
        ];
    }

    protected function privilegedShellScript(Server $server, string $quotedVersions): string
    {
        $inner = <<<'BASH'
bash -lc '
supported_versions=(__SUPPORTED_VERSIONS__)
supported=false
installed_versions=()

if command -v dpkg-query >/dev/null 2>&1; then
  supported=true
  for version in "${supported_versions[@]}"; do
    if dpkg-query -W -f='\${Status}' "php${version}-cli" 2>/dev/null | grep -q "install ok installed"; then
      installed_versions+=("$version")
    fi
  done
elif command -v php >/dev/null 2>&1; then
  supported=true
fi

default_version=""
if command -v php >/dev/null 2>&1; then
  default_version="$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null || true)"
fi

printf "supported=%s\n" "$supported"
printf "installed_versions=%s\n" "$(IFS=,; echo "${installed_versions[*]}")"
printf "detected_default_version=%s\n" "$default_version"
'
BASH;

        $inner = str_replace('__SUPPORTED_VERSIONS__', $quotedVersions, $inner);
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $inner;
        }

        return 'sudo -n '.$inner;
    }

    protected function packageActionLockKey(Server $server): string
    {
        return 'server-php-package-action:'.$server->id;
    }

    protected function packageActionLockSeconds(string $action): int
    {
        return match ($action) {
            'install', 'patch', 'uninstall' => 630,
            'set_cli_default', 'set_new_site_default' => 150,
            default => 630,
        };
    }

    protected function packageActionSuccessMessage(string $action, string $version): string
    {
        return match ($action) {
            'install' => __('PHP :version installed.', ['version' => $version]),
            'set_cli_default' => __('PHP :version is now the CLI default.', ['version' => $version]),
            'set_new_site_default' => __('PHP :version is now the default for new PHP sites.', ['version' => $version]),
            'patch' => __('PHP :version patched.', ['version' => $version]),
            'uninstall' => __('PHP :version uninstalled.', ['version' => $version]),
            default => __('PHP action completed.'),
        };
    }

    protected function packageActionScript(Server $server, string $action, string $version): string
    {
        $versionArg = escapeshellarg($version);

        $inner = match ($action) {
            'install' => "DEBIAN_FRONTEND=noninteractive apt-get install -y php{$version}-cli php{$version}-fpm",
            'set_cli_default' => "update-alternatives --set php /usr/bin/php{$version}",
            'set_new_site_default' => "printf %s {$versionArg} >/dev/null",
            'patch' => "DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade -y php{$version}-cli php{$version}-fpm",
            'uninstall' => "DEBIAN_FRONTEND=noninteractive apt-get remove -y php{$version}-cli php{$version}-fpm",
            default => throw new \RuntimeException('Unknown PHP package action.'),
        };

        if (trim((string) $server->ssh_user) === '' || trim((string) $server->ssh_user) === 'root') {
            return $inner;
        }

        return 'sudo -n bash -lc '.escapeshellarg($inner);
    }
}
