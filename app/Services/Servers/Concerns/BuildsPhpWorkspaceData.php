<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\ServerPhpSiteRuntimeMigrator;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsPhpWorkspaceData
{


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
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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
}
