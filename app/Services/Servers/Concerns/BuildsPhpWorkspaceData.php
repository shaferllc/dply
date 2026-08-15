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

            // Counts only — the full catalog is built lazily for whichever
            // version the operator expands, so a server with eight versions
            // does not pay for eight catalogs on every render.
            $rows[$id]['extension_count'] = ($row['is_installed'] ?? false)
                ? count($this->cachedExtensionsFor($server, (string) $id)['enabled'])
                : 0;
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
     * Catalog for one version, enriched with live host state and grouped for
     * the expandable panel under that version's row.
     *
     * State comes from the inventory probe: `available` is what sits in
     * mods-available (i.e. the package is installed), `enabled` is what the
     * cli/fpm conf.d symlinks actually load. A package can be installed but
     * disabled, which is exactly how you park Xdebug on a staging box.
     *
     * @return array{
     *     categories: list<array{key: string, label: string, rows: list<array<string, mixed>>}>,
     *     unlisted: list<string>,
     *     installed_count: int,
     *     enabled_count: int
     * }
     */
    public function extensionPanelData(Server $server, string $version): array
    {
        $state = $this->cachedExtensionsFor($server, $version);
        $available = array_flip($state['available']);
        $enabled = array_flip($state['enabled']);
        $catalog = $this->extensionCatalog($version);
        $categoryLabels = (array) config('server_php_extensions.categories', []);

        $grouped = [];
        $claimed = [];
        $installedCount = 0;

        foreach ($catalog as $row) {
            $modules = $row['modules'];
            $isInstalled = false;
            $isEnabled = false;

            foreach ($modules as $module) {
                $module = strtolower($module);
                $claimed[$module] = true;

                if (isset($available[$module])) {
                    $isInstalled = true;
                }

                if (isset($enabled[$module])) {
                    $isEnabled = true;
                }
            }

            // Bundled extensions live inside php-common, so they may never
            // appear in mods-available. Treat them as always present.
            if ($row['bundled']) {
                $isInstalled = true;
            }

            // Enabled implies installed — covers builtins compiled straight
            // into the binary with no mods-available entry at all.
            if ($isEnabled) {
                $isInstalled = true;
            }

            if ($isInstalled) {
                $installedCount++;
            }

            $category = $row['category'];
            $grouped[$category] ??= [];
            $grouped[$category][] = $row + [
                'is_installed' => $isInstalled,
                'is_enabled' => $isEnabled,
            ];
        }

        $categories = [];

        foreach ($categoryLabels as $key => $label) {
            if (($grouped[$key] ?? []) === []) {
                continue;
            }

            $categories[] = [
                'key' => (string) $key,
                'label' => (string) $label,
                'rows' => $grouped[$key],
            ];
            unset($grouped[$key]);
        }

        // Anything left carries a category the config does not name.
        foreach ($grouped as $key => $rows) {
            $categories[] = ['key' => (string) $key, 'label' => __('Other'), 'rows' => $rows];
        }

        // Modules on the host that no catalog entry claims. Surfaced read-only
        // so the panel does not imply the curated list is the whole truth.
        $unlisted = array_values(array_filter(
            $state['available'],
            static fn (string $module): bool => ! isset($claimed[$module]),
        ));
        sort($unlisted);

        return [
            'categories' => $categories,
            'unlisted' => $unlisted,
            'installed_count' => $installedCount,
            'enabled_count' => count($state['enabled']),
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
            'server_php_workspace_url' => route('servers.runtime', $server, false),
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
            'extensions' => $this->siteExtensionSummary($server, $currentVersion),
        ];
    }

    /**
     * Read-only extension state for the site PHP panel. Management stays on
     * the server workspace — extensions are per-version and shared by every
     * site on the box, so editing them from one site would be misleading.
     *
     * @return array{summary: string, enabled: list<string>, count: int}
     */
    protected function siteExtensionSummary(Server $server, ?string $version): array
    {
        if ($version === null) {
            return [
                'summary' => __('Set a PHP version for this site to see its loaded extensions.'),
                'enabled' => [],
                'count' => 0,
            ];
        }

        $enabled = $this->cachedExtensionsFor($server, $version)['enabled'];

        if ($enabled === []) {
            return [
                'summary' => __('No extension inventory yet for PHP :version. Refresh it from the server PHP workspace.', ['version' => $version]),
                'enabled' => [],
                'count' => 0,
            ];
        }

        return [
            'summary' => trans_choice(
                ':count extension loaded for PHP :version. Manage them from the server PHP workspace.|:count extensions loaded for PHP :version. Manage them from the server PHP workspace.',
                count($enabled),
                ['count' => count($enabled), 'version' => $version],
            ),
            'enabled' => $enabled,
            'count' => count($enabled),
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
}
