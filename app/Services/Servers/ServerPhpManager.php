<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Support\Servers\ServerPhpMutationLock;

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

        // Sites' PHP version moved from `php_version` → `runtime_version`
        // (when runtime='php') per the multi-runtime strategy memo.
        $siteCounts = $server->sites()
            ->selectRaw('runtime_version, COUNT(*) as aggregate')
            ->where('runtime', 'php')
            ->whereNotNull('runtime_version')
            ->groupBy('runtime_version')
            ->pluck('aggregate', 'runtime_version')
            ->all();

        $installedIds = $this->normalizeVersionList($inventory['installed_versions'] ?? []);
        if ($installedIds === []) {
            $installedIds = $this->inferInstalledVersionIds($server, $meta);
        }
        $installedVersions = [];

        foreach ($installedIds as $id) {
            $installedVersions[] = [
                'id' => $id,
                'label' => $supportedMap[$id] ?? 'PHP '.$id,
                'is_supported' => array_key_exists($id, $supportedMap),
                'site_count' => (int) ($siteCounts[$id] ?? 0),
            ];
        }

        $detectedDefaultVersion = $this->normalizeVersionId($inventory['detected_default_version'] ?? null);
        if ($detectedDefaultVersion === null || ! in_array($detectedDefaultVersion, $installedIds, true)) {
            $detectedDefaultVersion = $this->normalizeVersionId($meta['default_php_version'] ?? null)
                ?? $this->normalizeVersionId($meta['php_version'] ?? null)
                ?? ($installedIds[0] ?? null);
        }

        return [
            'is_supported_environment' => array_key_exists('supported', $inventory) ? (bool) $inventory['supported'] : null,
            'installed_versions' => $installedVersions,
            'detected_default_version' => $detectedDefaultVersion,
        ];
    }

    /**
     * @return list<string>
     */
    public function installedVersionIds(Server $server): array
    {
        return array_column($this->cachedInventory($server)['installed_versions'], 'id');
    }

    public function latestInstalledVersion(Server $server): ?string
    {
        $ids = $this->installedVersionIds($server);
        if ($ids === []) {
            return null;
        }

        usort($ids, static fn (string $a, string $b): int => version_compare($b, $a));

        return $ids[0];
    }

    /**
     * SSH probe for PHP packages actually present on the host (ignores stale meta).
     *
     * @return list<string>
     */
    public function probeInstalledVersionIds(Server $server): array
    {
        try {
            return $this->normalizeVersionList(
                $this->fetchRemoteInventory($server)['installed_versions'] ?? [],
            );
        } catch (\Throwable) {
            return $this->installedVersionIds($server);
        }
    }

    public function probeLatestInstalledVersion(Server $server): ?string
    {
        $ids = $this->probeInstalledVersionIds($server);
        if ($ids === []) {
            return null;
        }

        usort($ids, static fn (string $a, string $b): int => version_compare($b, $a));

        return $ids[0];
    }

    /**
     * Pick the PHP-FPM version Caddy should target: keep the configured
     * version when it is installed, otherwise the newest installed build.
     */
    public function resolveCaddyPhpVersion(Server $server, ?string $configured): string
    {
        $configured = $this->normalizeVersionId($configured) ?? '8.3';
        $installed = $this->installedVersionIds($server);

        if (in_array($configured, $installed, true)) {
            return $configured;
        }

        return $this->latestInstalledVersion($server)
            ?? $this->normalizeVersionId(data_get($server->meta, 'default_php_version'))
            ?? '8.3';
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

        $cliDefault = $this->normalizeVersionId($meta['default_php_version'] ?? null);
        if ($cliDefault === null || ! in_array($cliDefault, $installedIds, true)) {
            $cliDefault = $detectedDefault;
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
        $meta['default_php_version'] = $cliDefault;
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
     *     version_rows: list<array{id: string, label: string, is_supported: bool, is_installed: bool, site_count: int, migration_target_version: ?string}>
     * }
     */
    public function workspaceData(Server $server): array
    {
        $supportedVersions = $this->supportedVersions($server);
        $inventory = $this->cachedInventory($server);
        $defaults = $this->currentDefaults($server, $inventory);
        $rows = [];
        $migrator = app(ServerPhpSiteRuntimeMigrator::class);

        foreach ($supportedVersions as $version) {
            $rows[$version['id']] = [
                'id' => $version['id'],
                'label' => $version['label'],
                'is_supported' => true,
                'is_installed' => false,
                'site_count' => 0,
                'migration_target_version' => null,
            ];
        }

        foreach ($inventory['installed_versions'] as $version) {
            $rows[$version['id']] = [
                'id' => $version['id'],
                'label' => $version['label'],
                'is_supported' => $version['is_supported'],
                'is_installed' => true,
                'site_count' => $version['site_count'],
                'migration_target_version' => null,
            ];
        }

        $installedIds = array_values(array_filter(
            array_keys($rows),
            fn (string $id): bool => (bool) ($rows[$id]['is_installed'] ?? false),
        ));

        foreach ($rows as $id => $row) {
            if ($row['is_installed'] ?? false) {
                $rows[$id]['uninstall_fallback_version'] = $migrator->resolveMigrationTargetVersion($installedIds, $id);
            }

            if ((int) ($row['site_count'] ?? 0) > 0 && ($row['is_installed'] ?? false)) {
                $rows[$id]['migration_target_version'] = $rows[$id]['uninstall_fallback_version'];
            }
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
     * @return array{status: 'succeeded'|'stale', message: string, output?: ?string}
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
                'output' => $this->inventorySummaryOutput($freshInventory),
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
                'output' => $this->inventorySummaryOutput($freshInventory),
            ];
        }
    }

    public function isInventoryStale(Server $server): bool
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $refreshMeta = is_array($meta['php_inventory_refresh'] ?? null) ? $meta['php_inventory_refresh'] : [];

        return ($refreshMeta['status'] ?? null) === 'stale';
    }

    public function shouldSyncInventoryBeforePackageAction(Server $server): bool
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $refreshMeta = is_array($meta['php_inventory_refresh'] ?? null) ? $meta['php_inventory_refresh'] : [];
        $status = $refreshMeta['status'] ?? null;

        return in_array($status, ['stale', 'failed'], true);
    }

    /**
     * @param  callable(string $step, string $action, string $version): void|null  $onProgress
     * @return array{status: 'succeeded'|'stale', message: string, output?: ?string}
     */
    public function applyPackageAction(
        Server $server,
        string $action,
        string $version,
        ?callable $onProgress = null,
        bool $migrateSitesBeforeUninstall = false,
        ?string $actingUserId = null,
    ): array {
        $version = $this->normalizeVersionId($version) ?? '';
        $action = trim($action);

        if ($version === '' || $action === '') {
            throw new \RuntimeException('PHP action and version are required.');
        }

        $lock = ServerPhpMutationLock::acquire($server, $this->packageActionLockSeconds($action));
        $acquired = $lock->get();

        if (! $acquired) {
            throw new \RuntimeException('Another PHP package action is already running for this server.');
        }

        try {
            $server = $server->fresh();
            if ($server === null || ! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
                throw new \RuntimeException('Provisioning and SSH must be ready before managing PHP packages.');
            }

            $shouldSyncInventory = $this->shouldSyncInventoryBeforePackageAction($server);

            if ($shouldSyncInventory) {
                $onProgress?->__invoke('sync_inventory', $action, $version);
            }

            $preflightInventory = $this->fetchRemoteInventory($server);

            if ($shouldSyncInventory) {
                $this->syncInventorySnapshot($server, $preflightInventory);
                $server = $server->fresh() ?? $server;
            }

            if ($action === 'install' && $this->isVersionInstalledInInventory($version, $preflightInventory)) {
                return $this->completePackageActionWithInventory(
                    $server,
                    $action,
                    $version,
                    $preflightInventory,
                    null,
                    __('PHP :version is already installed.', ['version' => $version]),
                );
            }

            if ($action === 'set_cli_default' && $this->isCliDefaultInInventory($version, $preflightInventory)) {
                return $this->completePackageActionWithInventory(
                    $server,
                    $action,
                    $version,
                    $preflightInventory,
                    null,
                    __('PHP :version is already the CLI default.', ['version' => $version]),
                );
            }

            if ($action === 'uninstall' && ! $this->isVersionInstalledInInventory($version, $preflightInventory)) {
                return $this->completePackageActionWithInventory(
                    $server,
                    $action,
                    $version,
                    $preflightInventory,
                    null,
                    __('PHP :version is not installed.', ['version' => $version]),
                );
            }

            if ($action === 'uninstall' && $migrateSitesBeforeUninstall) {
                $this->migrateSitesBlockingUninstall($server, $version, $preflightInventory, $onProgress, $actingUserId);
                $server = $server->fresh() ?? $server;
            }

            if ($action === 'uninstall') {
                $preflightInventory = $this->reassignDefaultsBeforeUninstall(
                    $server,
                    $version,
                    $preflightInventory,
                    $onProgress,
                );
                $server = $server->fresh() ?? $server;
            }

            if ($action === 'migrate_sites') {
                $this->guardPackageAction($server, $action, $version, $preflightInventory);

                return $this->runSiteMigrationPackageAction(
                    $server,
                    $version,
                    $preflightInventory,
                    $onProgress,
                    $actingUserId,
                );
            }

            $this->guardPackageAction($server, $action, $version, $preflightInventory);

            $onProgress?->__invoke('execute', $action, $version);

            $commandOutput = $this->executePackageAction($server, $action, $version);

            $freshInventory = $this->fetchRemoteInventory($server->fresh());

            return $this->completePackageActionWithInventory(
                $server,
                $action,
                $version,
                $freshInventory,
                $commandOutput,
                $this->packageActionSuccessMessage($action, $version),
            );
        } finally {
            ServerPhpMutationLock::releaseIfOwned($lock, $acquired);
        }
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $freshInventory
     * @return array<string, mixed>
     */
    protected function refreshedInventoryMeta(
        Server $server,
        array $freshInventory,
        ?string $cliDefaultVersion = null,
        ?string $newSiteDefaultVersion = null,
    ): array {
        $meta = $this->reconcileFreshInventory($server->fresh(), $freshInventory);

        if ($cliDefaultVersion !== null) {
            $meta['default_php_version'] = $cliDefaultVersion;
            if (is_array($meta['php_inventory'] ?? null)) {
                $meta['php_inventory']['detected_default_version'] = $cliDefaultVersion;
            }
        }

        if ($newSiteDefaultVersion !== null) {
            $meta['php_new_site_default_version'] = $newSiteDefaultVersion;
        }

        $meta['php_inventory_refresh'] = [
            'status' => 'succeeded',
            'started_at' => data_get($server->meta, 'php_inventory_refresh.started_at'),
            'refreshed_at' => now()->toIso8601String(),
            'error' => null,
            'failed_at' => null,
            'stale_at' => null,
        ];

        return $meta;
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $freshInventory
     */
    protected function syncInventorySnapshot(Server $server, array $freshInventory): bool
    {
        try {
            $this->persistRefreshedInventoryMeta(
                $server->fresh(),
                $this->refreshedInventoryMeta($server, $freshInventory),
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $freshInventory
     * @return array{status: 'succeeded'|'stale', message: string, output?: ?string}
     */
    protected function completePackageActionWithInventory(
        Server $server,
        string $action,
        string $version,
        array $freshInventory,
        ?string $commandOutput,
        string $message,
    ): array {
        $newSiteDefaultVersion = $action === 'set_new_site_default' ? $version : null;
        $cliDefaultVersion = $action === 'set_cli_default' ? $version : null;

        try {
            $this->persistRefreshedInventoryMeta(
                $server->fresh(),
                $this->refreshedInventoryMeta($server, $freshInventory, $cliDefaultVersion, $newSiteDefaultVersion),
            );

            return [
                'status' => 'succeeded',
                'message' => $message,
                'output' => $this->packageActionOutput($commandOutput, $freshInventory),
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
                'output' => $this->packageActionOutput($commandOutput, $freshInventory),
            ];
        }
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $inventory
     */
    protected function isVersionInstalledInInventory(string $version, array $inventory): bool
    {
        return in_array($version, $this->normalizeVersionList($inventory['installed_versions'] ?? []), true);
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $inventory
     */
    protected function isCliDefaultInInventory(string $version, array $inventory): bool
    {
        $normalized = $this->normalizeVersionId($version);

        return $normalized !== null
            && $normalized === $this->normalizeVersionId($inventory['detected_default_version'] ?? null);
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $preflightInventory
     */
    protected function migrateSitesBlockingUninstall(
        Server $server,
        string $version,
        array $preflightInventory,
        ?callable $onProgress,
        ?string $actingUserId,
    ): void {
        $migrator = app(ServerPhpSiteRuntimeMigrator::class);
        $siteCount = $migrator->countSitesUsingVersion($server, $version);

        if ($siteCount === 0) {
            return;
        }

        $installedIds = $this->normalizeVersionList($preflightInventory['installed_versions'] ?? []);
        $target = $migrator->resolveMigrationTargetVersion($installedIds, $version);

        if ($target === null) {
            throw new \RuntimeException('Install another PHP version before uninstalling PHP '.$version.' while sites still use it.');
        }

        $onProgress?->__invoke('migrate_sites', 'uninstall', $version);
        $migrator->migrateSitesUsingVersion($server, $version, $target, $actingUserId);
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $preflightInventory
     * @return array{status: 'succeeded'|'stale', message: string, output?: ?string}
     */
    protected function runSiteMigrationPackageAction(
        Server $server,
        string $version,
        array $preflightInventory,
        ?callable $onProgress,
        ?string $actingUserId,
    ): array {
        $migrator = app(ServerPhpSiteRuntimeMigrator::class);
        $installedIds = $this->normalizeVersionList($preflightInventory['installed_versions'] ?? []);
        $target = $migrator->resolveMigrationTargetVersion($installedIds, $version);

        if ($target === null) {
            throw new \RuntimeException('Install another PHP version before moving sites off PHP '.$version.'.');
        }

        $onProgress?->__invoke('migrate_sites', 'migrate_sites', $version);
        $summary = $migrator->migrateSitesUsingVersion($server, $version, $target, $actingUserId);

        return $this->completePackageActionWithInventory(
            $server,
            'migrate_sites',
            $version,
            $preflightInventory,
            $this->siteMigrationOutput($summary),
            trans_choice(
                ':count site moved to PHP :target. Webserver configs are queued to apply on each site.|:count sites moved to PHP :target. Webserver configs are queued to apply on each site.',
                $summary['migrated_count'],
                ['count' => $summary['migrated_count'], 'target' => $summary['target_version']],
            ),
        );
    }

    /**
     * @param  array{migrated_count: int, target_version: string, site_names: list<string>}  $summary
     */
    protected function siteMigrationOutput(array $summary): string
    {
        $lines = [
            __('Moved :count site(s) to PHP :target.', [
                'count' => $summary['migrated_count'],
                'target' => $summary['target_version'],
            ]),
        ];

        if ($summary['site_names'] !== []) {
            $lines[] = __('Sites: :names', ['names' => implode(', ', $summary['site_names'])]);
        }

        $lines[] = __('Queued webserver config apply for each site.');

        return implode("\n", $lines);
    }

    public function isMutationInFlight(Server $server): bool
    {
        return ServerPhpMutationLock::isHeld($server);
    }

    public function normalizeVersionId(mixed $value): ?string
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
     * @return list<string>
     */
    public function normalizeVersionList(mixed $value): array
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
        $detectedDefaultVersion = $this->normalizeVersionId($inventory['detected_default_version'] ?? null);

        if (
            $detectedDefaultVersion !== null
            && in_array($detectedDefaultVersion, $supportedIds, true)
            && ! in_array($detectedDefaultVersion, $installedIds, true)
        ) {
            $installedIds[] = $detectedDefaultVersion;
        }

        $siteCount = (int) $server->sites()
            ->where('runtime', 'php')
            ->where('runtime_version', $version)
            ->count();
        $defaults = $this->currentDefaults($server, [
            'installed_versions' => array_map(fn (string $id) => [
                'id' => $id,
                'label' => 'PHP '.$id,
                'is_supported' => in_array($id, $supportedIds, true),
                'site_count' => 0,
            ], $installedIds),
            'detected_default_version' => $detectedDefaultVersion,
            'is_supported_environment' => (bool) ($inventory['supported'] ?? true),
        ]);

        if (! ($inventory['supported'] ?? true)) {
            throw new \RuntimeException('This server does not report a supported PHP package environment.');
        }

        match ($action) {
            'install' => $this->guardInstallAction($version, $supportedIds),
            'set_cli_default' => $this->guardSetCliDefaultAction($version, $installedIds),
            'set_new_site_default' => $this->guardSetNewSiteDefaultAction($version, $installedIds),
            'patch' => $this->guardPatchAction($version, $installedIds),
            'migrate_sites' => $this->guardMigrateSitesAction($server, $version, $installedIds),
            'uninstall' => $this->guardUninstallAction($version, $installedIds, $siteCount, $defaults),
            default => throw new \RuntimeException('Unknown PHP package action.'),
        };
    }

    /**
     * @param  list<string>  $installedIds
     */
    protected function guardMigrateSitesAction(Server $server, string $version, array $installedIds): void
    {
        if (! in_array($version, $installedIds, true)) {
            throw new \RuntimeException('PHP '.$version.' is not installed.');
        }

        if (app(ServerPhpSiteRuntimeMigrator::class)->countSitesUsingVersion($server, $version) === 0) {
            throw new \RuntimeException('No PHP sites on this server are using PHP '.$version.'.');
        }

        $target = app(ServerPhpSiteRuntimeMigrator::class)->resolveMigrationTargetVersion($installedIds, $version);
        if ($target === null) {
            throw new \RuntimeException('Install another PHP version before moving sites off PHP '.$version.'.');
        }
    }

    /**
     * @param  list<string>  $supportedIds
     */
    protected function guardInstallAction(string $version, array $supportedIds): void
    {
        if (! in_array($version, $supportedIds, true)) {
            throw new \RuntimeException('PHP '.$version.' is not supported on this server.');
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
            throw new \RuntimeException(trans_choice(
                'PHP :version is still used by :count site. Upgrade those sites to another installed PHP version before uninstalling, or choose migrate sites and uninstall.|PHP :version is still used by :count sites. Upgrade those sites to another installed PHP version before uninstalling, or choose migrate sites and uninstall.',
                $siteCount,
                ['version' => $version, 'count' => $siteCount],
            ));
        }

        if (($defaults['cli_default'] ?? null) === $version || ($defaults['new_site_default'] ?? null) === $version) {
            throw new \RuntimeException('Install another PHP version before uninstalling PHP '.$version.' while it is still a server default.');
        }
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $preflightInventory
     * @return array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}
     */
    protected function reassignDefaultsBeforeUninstall(
        Server $server,
        string $version,
        array $preflightInventory,
        ?callable $onProgress,
    ): array {
        $installedIds = $this->normalizeVersionList($preflightInventory['installed_versions'] ?? []);
        $fallback = app(ServerPhpSiteRuntimeMigrator::class)->resolveMigrationTargetVersion($installedIds, $version);

        if ($fallback === null) {
            return $preflightInventory;
        }

        $detectedCli = $this->normalizeVersionId($preflightInventory['detected_default_version'] ?? null);
        $defaults = $this->currentDefaults($server, [
            'installed_versions' => array_map(
                fn (string $id): array => [
                    'id' => $id,
                    'label' => 'PHP '.$id,
                    'is_supported' => true,
                    'site_count' => 0,
                ],
                $installedIds,
            ),
            'detected_default_version' => $detectedCli,
            'is_supported_environment' => (bool) ($preflightInventory['supported'] ?? true),
        ]);

        $needsCliReassign = ($defaults['cli_default'] ?? null) === $version || $detectedCli === $version;
        $needsNewSiteReassign = ($defaults['new_site_default'] ?? null) === $version;

        if (! $needsCliReassign && ! $needsNewSiteReassign) {
            return $preflightInventory;
        }

        $cliPersist = null;
        $newSitePersist = null;

        if ($needsCliReassign) {
            $onProgress?->__invoke('reassign_cli_default', 'uninstall', $version);
            $this->executePackageAction($server, 'set_cli_default', $fallback);
            $preflightInventory = $this->fetchRemoteInventory($server->fresh() ?? $server);
            $cliPersist = $fallback;
        }

        if ($needsNewSiteReassign) {
            $onProgress?->__invoke('reassign_new_site_default', 'uninstall', $version);
            $newSitePersist = $fallback;
        }

        if ($cliPersist !== null || $newSitePersist !== null) {
            $this->persistRefreshedInventoryMeta(
                $server->fresh() ?? $server,
                $this->refreshedInventoryMeta(
                    $server->fresh() ?? $server,
                    $preflightInventory,
                    $cliPersist,
                    $newSitePersist,
                ),
            );
        }

        return $preflightInventory;
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
        $output = app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($this->privilegedShellScript($server, $quotedVersions), 120),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );

        return $this->parseRemoteInventoryOutput($output);
    }

    protected function executePackageAction(Server $server, string $action, string $version): string
    {
        return app(ServerSshConnectionRunner::class)->run(
            $server,
            function ($ssh) use ($server, $action, $version): string {
                $output = $ssh->exec($this->packageActionScript($server, $action, $version), 600);
                $exitCode = $ssh->lastExecExitCode();

                if ($exitCode !== null && $exitCode !== 0) {
                    $trimmed = trim($output);

                    throw new \RuntimeException(
                        $trimmed !== ''
                            ? $trimmed
                            : __('Remote PHP command failed (exit :code).', ['code' => $exitCode]),
                    );
                }

                return $output;
            },
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $inventory
     */
    protected function inventorySummaryOutput(array $inventory): string
    {
        $installed = $this->normalizeVersionList($inventory['installed_versions'] ?? []);
        $default = $this->normalizeVersionId($inventory['detected_default_version'] ?? null);

        return trim(implode("\n", array_filter([
            'Supported environment: '.(($inventory['supported'] ?? false) ? 'yes' : 'no'),
            'Installed versions: '.($installed !== [] ? implode(', ', $installed) : 'none reported'),
            'Detected CLI default: '.($default ?? 'none reported'),
        ])));
    }

    /**
     * @param  array{supported: bool, installed_versions: list<string>, detected_default_version: ?string}  $inventory
     */
    protected function packageActionOutput(?string $commandOutput, array $inventory): string
    {
        $parts = [];
        $trimmedCommandOutput = trim($commandOutput ?? '');

        if ($trimmedCommandOutput !== '') {
            $parts[] = $trimmedCommandOutput;
        }

        $parts[] = $this->inventorySummaryOutput($inventory);

        return implode("\n\n", array_filter($parts));
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
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    protected function inferInstalledVersionIds(Server $server, array $meta): array
    {
        $inferred = [];

        foreach ([
            $meta['default_php_version'] ?? null,
            $meta['php_new_site_default_version'] ?? null,
            $meta['php_version'] ?? null,
        ] as $candidate) {
            $normalized = $this->normalizeVersionId($candidate);
            if ($normalized !== null) {
                $inferred[] = $normalized;
            }
        }

        foreach ($server->sites()->where('runtime', 'php')->whereNotNull('runtime_version')->pluck('runtime_version')->all() as $siteVersion) {
            $normalized = $this->normalizeVersionId($siteVersion);
            if ($normalized !== null) {
                $inferred[] = $normalized;
            }
        }

        return array_values(array_unique($inferred));
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
php_runtime_installed() {
  local version="$1"
  if dpkg-query -W -f='\''${Status}'\'' "php${version}-cli" 2>/dev/null | grep -q "install ok installed"; then
    return 0
  fi
  if dpkg-query -W -f='\''${Status}'\'' "php${version}-fpm" 2>/dev/null | grep -q "install ok installed"; then
    return 0
  fi
  if dpkg-query -W -f='\''${Package}\n'\'' "php${version}-*" 2>/dev/null | grep -qE "^php${version}-"; then
    return 0
  fi
  if command -v "php${version}" >/dev/null 2>&1; then
    return 0
  fi
  if command -v "php-fpm${version}" >/dev/null 2>&1; then
    return 0
  fi
  if [ -x "/usr/bin/php${version}" ] || [ -x "/usr/sbin/php-fpm${version}" ]; then
    return 0
  fi
  return 1
}

supported_versions=(__SUPPORTED_VERSIONS__)
supported=false
installed_versions=()

if command -v dpkg-query >/dev/null 2>&1; then
  supported=true
  for version in "${supported_versions[@]}"; do
    if php_runtime_installed "$version"; then
      installed_versions+=("$version")
    fi
  done
  for d in /etc/php/*/fpm; do
    [ -d "$d" ] || continue
    version="$(basename "$(dirname "$d")")"
    case " ${installed_versions[*]} " in
      *" ${version} "*) ;;
      *) php_runtime_installed "$version" && installed_versions+=("$version") ;;
    esac
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
        return ServerPhpMutationLock::key($server);
    }

    protected function packageActionLockSeconds(string $action): int
    {
        return match ($action) {
            'install', 'patch', 'uninstall' => 630,
            'set_cli_default', 'set_new_site_default', 'migrate_sites' => 630,
            default => 630,
        };
    }

    protected function packageActionSuccessMessage(string $action, string $version): string
    {
        return match ($action) {
            'install' => __('PHP :version installed.', ['version' => $version]),
            'set_cli_default' => __('PHP :version is now the CLI default.', ['version' => $version]),
            'set_new_site_default' => __('PHP :version is now the default for new PHP sites.', ['version' => $version]),
            'migrate_sites' => __('Sites moved off PHP :version.', ['version' => $version]),
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
            'set_cli_default' => $this->setCliDefaultScript($version),
            'set_new_site_default' => "printf %s {$versionArg} >/dev/null",
            'patch' => "DEBIAN_FRONTEND=noninteractive apt-get install --only-upgrade -y php{$version}-cli php{$version}-fpm",
            'uninstall' => $this->uninstallPhpScript($version),
            default => throw new \RuntimeException('Unknown PHP package action.'),
        };

        $script = str_contains($inner, "\n")
            ? 'bash -lc '.escapeshellarg($inner)
            : $inner;

        if (trim((string) $server->ssh_user) === '' || trim((string) $server->ssh_user) === 'root') {
            return $script;
        }

        return 'sudo -n '.$script;
    }

    protected function setCliDefaultScript(string $version): string
    {
        $versionDigits = preg_replace('/\D/', '', $version) ?? $version;

        return implode("\n", [
            'set -e',
            "target=/usr/bin/php{$version}",
            'if [ ! -x "$target" ]; then',
            '  echo "PHP binary not found: $target" >&2',
            '  exit 1',
            'fi',
            "priority={$versionDigits}",
            'if update-alternatives --query php >/dev/null 2>&1; then',
            '  update-alternatives --install /usr/bin/php php "$target" "$priority" 2>/dev/null || true',
            '  update-alternatives --set php "$target"',
            'else',
            '  update-alternatives --install /usr/bin/php php "$target" "$priority"',
            'fi',
        ]);
    }

    protected function uninstallPhpScript(string $version): string
    {
        return implode("\n", [
            'set -e',
            "version={$version}",
            'packages="$(dpkg-query -W -f=\'${Package}\n\' "php${version}-*" 2>/dev/null | grep -E "^php${version}-" || true)"',
            'if [ -n "$packages" ]; then',
            '  DEBIAN_FRONTEND=noninteractive apt-get purge -y $packages',
            'fi',
            'if [ -d "/etc/php/${version}" ]; then',
            '  rm -rf "/etc/php/${version}"',
            'fi',
            'if command -v update-alternatives >/dev/null 2>&1; then',
            '  update-alternatives --auto php 2>/dev/null || true',
            'fi',
        ]);
    }

    protected function useRootSsh(): bool
    {
        return true;
    }

    protected function fallbackToDeployUserSsh(): bool
    {
        return true;
    }
}
