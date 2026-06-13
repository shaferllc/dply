<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\ServerSshConnectionRunner;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ResolvesPhpInventory
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
}
