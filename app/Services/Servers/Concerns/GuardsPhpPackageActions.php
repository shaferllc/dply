<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\ServerPhpSiteRuntimeMigrator;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait GuardsPhpPackageActions
{


    public function shouldSyncInventoryBeforePackageAction(Server $server): bool
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $refreshMeta = is_array($meta['php_inventory_refresh'] ?? null) ? $meta['php_inventory_refresh'] : [];
        $status = $refreshMeta['status'] ?? null;

        return in_array($status, ['stale', 'failed'], true);
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
}
