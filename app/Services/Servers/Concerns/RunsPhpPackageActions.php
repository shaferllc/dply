<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\ServerPhpSiteRuntimeMigrator;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Support\Servers\ServerPhpMutationLock;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait RunsPhpPackageActions
{


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
    /** @return array<string, mixed> */
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
}
