<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceServices;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * View-model for the server Services workspace blade tree. Keeps banner/setup
 * and tab preamble logic out of {@see resources/views/livewire/servers/workspace-services.blade.php}.
 */
final class ServicesWorkspaceViewData
{
    /**
     * @param  list<array<string, mixed>>  $systemdServiceActivity
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        WorkspaceServices $component,
        bool $includeBannerContext = false,
        bool $includeInventoryContext = false,
        bool $includeActivityContext = false,
        array $systemdServiceActivity = [],
        ?string $systemdInventoryFetchedAt = null,
    ): array {
        $card = 'dply-card';
        $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
        $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors disabled:cursor-not-allowed disabled:opacity-50';
        $btnDanger = 'inline-flex items-center justify-center gap-2 rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white shadow-sm hover:bg-red-700 transition-colors disabled:cursor-not-allowed disabled:opacity-50';

        $customMeta = $server->meta['custom_systemd_services'] ?? [];
        $customMetaList = is_array($customMeta)
            ? array_values(array_filter($customMeta, fn ($v) => is_string($v) && $v !== ''))
            : [];

        $data = compact(
            'card',
            'btnPrimary',
            'btnSecondary',
            'btnDanger',
            'customMetaList',
        );

        if ($includeBannerContext) {
            $actionBusy = in_array($component->systemdActionBannerStatus, ['queued', 'running'], true);
            $actionSettled = in_array($component->systemdActionBannerStatus, ['completed', 'failed'], true);
            $showActionBanner = $actionBusy || $actionSettled;
            $actionUnitLabel = $component->systemdActionBannerUnit;
            $actionVerbBusy = match ($component->systemdActionBannerKind) {
                'start' => __('Starting :unit…', ['unit' => $actionUnitLabel]),
                'stop' => __('Stopping :unit…', ['unit' => $actionUnitLabel]),
                'restart' => __('Restarting :unit…', ['unit' => $actionUnitLabel]),
                'reload' => __('Reloading :unit…', ['unit' => $actionUnitLabel]),
                'enable' => __('Enabling :unit at boot…', ['unit' => $actionUnitLabel]),
                'disable' => __('Disabling :unit at boot…', ['unit' => $actionUnitLabel]),
                'bulk-restart' => __('Restarting :unit…', ['unit' => $actionUnitLabel]),
                'bulk-stop' => __('Stopping :unit…', ['unit' => $actionUnitLabel]),
                'inventory-sync' => __('Syncing inventory on :host …', ['host' => $server->getSshConnectionString()]),
                default => __('Running on :unit…', ['unit' => $actionUnitLabel]),
            };
            $actionVerbDone = match ($component->systemdActionBannerKind) {
                'start' => __('Started :unit', ['unit' => $actionUnitLabel]),
                'stop' => __('Stopped :unit', ['unit' => $actionUnitLabel]),
                'restart' => __('Restarted :unit', ['unit' => $actionUnitLabel]),
                'reload' => __('Reloaded :unit', ['unit' => $actionUnitLabel]),
                'enable' => __('Enabled :unit at boot', ['unit' => $actionUnitLabel]),
                'disable' => __('Disabled :unit at boot', ['unit' => $actionUnitLabel]),
                'bulk-restart' => __('Bulk restart finished — :unit', ['unit' => $actionUnitLabel]),
                'bulk-stop' => __('Bulk stop finished — :unit', ['unit' => $actionUnitLabel]),
                'inventory-sync' => __('Inventory synced'),
                default => __('Action finished — :unit', ['unit' => $actionUnitLabel]),
            };
            $actionVerbFailed = match ($component->systemdActionBannerKind) {
                'bulk-restart', 'bulk-stop' => __('Bulk action failed — :unit', ['unit' => $actionUnitLabel]),
                'inventory-sync' => __('Inventory sync failed'),
                default => __('Action failed — :unit', ['unit' => $actionUnitLabel]),
            };
            $actionMessage = match ($component->systemdActionBannerStatus) {
                'queued', 'running' => $actionVerbBusy,
                'completed' => $actionVerbDone,
                'failed' => $actionVerbFailed,
                default => '',
            };
            $actionFinishedRel = null;
            if ($component->systemdActionBannerFinishedAt) {
                try {
                    $actionFinishedRel = Carbon::parse($component->systemdActionBannerFinishedAt)->diffForHumans();
                } catch (\Throwable) {
                    $actionFinishedRel = null;
                }
            }
            $actionSubtitle = match (true) {
                $component->systemdActionBannerStatus === 'queued' => __('Task queued — waiting for a worker to pick it up.'),
                $component->systemdActionBannerStatus === 'running' => __('Running on :host …', ['host' => $server->getSshConnectionString()]),
                $component->systemdActionBannerStatus === 'failed' && $component->systemdActionBannerError => $component->systemdActionBannerError,
                $component->systemdActionBannerStatus === 'completed' && $actionFinishedRel => __('Finished :time', ['time' => $actionFinishedRel]),
                default => null,
            };

            $systemdSyncMeta = $component->systemdInventorySyncMeta();
            $syncStatus = (string) ($systemdSyncMeta['status'] ?? '');
            $syncAt = $systemdSyncMeta['at'] ?? null;
            $syncError = (string) ($systemdSyncMeta['error'] ?? '');
            $syncDurationMs = $systemdSyncMeta['duration_ms'] ?? null;
            $syncDismissed = $component->systemdSyncBannerDismissedAt !== null
                && (string) $component->systemdSyncBannerDismissedAt === (string) $syncAt;
            $showSyncBanner = ! $showActionBanner
                && ! $syncDismissed
                && in_array($syncStatus, ['success', 'failed'], true);
            $syncBannerStatus = $syncStatus === 'success' ? 'completed' : 'failed';
            $syncRel = null;
            if ($syncAt !== null) {
                try {
                    $syncRel = Carbon::parse($syncAt)->diffForHumans();
                } catch (\Throwable) {
                    $syncRel = null;
                }
            }
            $syncBannerMessage = $syncStatus === 'success'
                ? __('Inventory sync succeeded')
                : __('Last inventory sync failed');
            $syncBannerSubtitle = match (true) {
                $syncStatus === 'failed' && $syncError !== '' => $syncError,
                $syncStatus === 'success' && $syncDurationMs !== null && $syncRel !== null => __('Finished :time · in :ms ms', ['time' => $syncRel, 'ms' => (int) $syncDurationMs]),
                $syncStatus === 'success' && $syncRel !== null => __('Finished :time', ['time' => $syncRel]),
                $syncStatus === 'failed' && $syncRel !== null => __('Failed :time', ['time' => $syncRel]),
                default => null,
            };

            $data = array_merge($data, compact(
                'actionBusy',
                'showActionBanner',
                'actionMessage',
                'actionSubtitle',
                'showSyncBanner',
                'syncBannerStatus',
                'syncBannerMessage',
                'syncBannerSubtitle',
            ));
        }

        if ($includeInventoryContext) {
            $manageableSystemdCount = collect($component->systemdInventory ?? [])
                ->filter(fn ($r) => ! empty($r['may_mutate']))
                ->count();
            $managedTiles = self::managedServiceTiles($server);
            $systemHiddenCount = $component->systemdHiddenSystemCount();
            $selectedCount = count($component->systemdSelectedList ?? []);
            $syncInFlight = $component->systemdActionBannerKind === 'inventory-sync'
                && in_array($component->systemdActionBannerStatus, ['queued', 'running'], true);

            $snapHuman = null;
            if ($systemdInventoryFetchedAt) {
                try {
                    $snapHuman = Carbon::parse($systemdInventoryFetchedAt)
                        ->timezone(config('app.timezone'))
                        ->diffForHumans();
                } catch (\Throwable) {
                    $snapHuman = null;
                }
            }

            $data = array_merge($data, compact(
                'manageableSystemdCount',
                'managedTiles',
                'systemHiddenCount',
                'selectedCount',
                'syncInFlight',
                'snapHuman',
            ));
        }

        if ($includeActivityContext) {
            $activityCount = count($systemdServiceActivity);
            $latestActivityRel = null;
            if ($activityCount > 0) {
                try {
                    $latestActivityRel = Carbon::parse($systemdServiceActivity[0]['at'] ?? null)
                        ->timezone(config('app.timezone'))
                        ->diffForHumans();
                } catch (\Throwable) {
                    $latestActivityRel = null;
                }
            }

            $data = array_merge($data, compact(
                'activityCount',
                'latestActivityRel',
            ));
        }

        return $data;
    }

    /**
     * @return Collection<int, array{key: string, label: string, icon: string, href: string, detail: string, shown: bool}>
     */
    private static function managedServiceTiles(Server $server): Collection
    {
        $installedTags = ServerInstalledServices::tagsFor($server);
        $cacheRows = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->get(['engine', 'name', 'status']);
        $cacheCounts = $cacheRows->groupBy('engine')->map->count();
        $databaseRows = class_exists(ServerDatabase::class)
            ? ServerDatabase::query()->where('server_id', $server->id)->count()
            : 0;
        $webserverActive = strtolower((string) (($server->meta ?? [])['webserver'] ?? 'nginx'));
        $phpInventory = is_array(($server->meta ?? [])['php_inventory'] ?? null) ? $server->meta['php_inventory'] : [];
        $phpVersionsInstalled = is_array($phpInventory['installed_versions'] ?? null) ? $phpInventory['installed_versions'] : [];

        // The php_inventory meta is only populated once the PHP workspace probe
        // runs. Until then, fall back to the version reconciled during provision
        // (stack_summary / installed_stack) so a server that ships with PHP
        // doesn't read "Not installed" just because nobody opened the PHP tab.
        $phpActiveVersion = ServerInstalledServices::phpVersionFor($server)
            ?? InstalledStack::fromMeta($server)->phpVersion;
        $phpInstalled = array_key_exists('php', $installedTags);
        $phpDetail = match (true) {
            $phpVersionsInstalled !== [] => trans_choice(':n version|:n versions', count($phpVersionsInstalled), ['n' => count($phpVersionsInstalled)]),
            $phpActiveVersion !== null && $phpActiveVersion !== '' => __('PHP :version', ['version' => $phpActiveVersion]),
            $phpInstalled => __('Installed'),
            default => __('Not installed'),
        };

        $installedStack = InstalledStack::fromMeta($server);

        // Same idea as PHP: the database row count only reflects user-created
        // databases, so a fresh server with a provisioned engine reads "No
        // databases yet". Surface the installed engine instead. The
        // installed-service tag is the reliable signal for *which* managed
        // engine landed (the reconciled stack can mislabel it, e.g. report a
        // low-memory SQLite fallback while MySQL is the real managed engine);
        // SQLite is a file, not a managed server, so it stays out of this card.
        $databaseEngineLabel = match (true) {
            array_key_exists('postgres', $installedTags) => 'PostgreSQL',
            array_key_exists('mysql', $installedTags) => 'MySQL',
            default => null,
        };
        // Only trust the reconciled version when it belongs to the tagged engine.
        if ($databaseEngineLabel !== null && $installedStack->databaseVersion) {
            $stackDb = strtolower((string) ($installedStack->database ?? ''));
            $versionMatchesEngine = ($databaseEngineLabel === 'PostgreSQL' && str_starts_with($stackDb, 'postgres'))
                || ($databaseEngineLabel === 'MySQL' && (str_starts_with($stackDb, 'mysql') || str_starts_with($stackDb, 'mariadb')));
            if ($versionMatchesEngine) {
                $databaseEngineLabel .= ' '.$installedStack->databaseVersion;
            }
        }
        $databasesShown = $databaseEngineLabel !== null;
        $databasesDetail = match (true) {
            $databaseRows > 0 => trans_choice(':n database|:n databases', $databaseRows, ['n' => $databaseRows]),
            $databaseEngineLabel !== null => $databaseEngineLabel,
            default => __('No databases yet'),
        };

        // Caches: a provisioned engine (redis/valkey/memcached) may not have a
        // ServerCacheService row yet — fall back to the installed-service tags
        // so the card names the engine rather than reading "No instances yet".
        $cacheTagLabels = collect(['redis', 'valkey', 'memcached'])
            ->filter(fn (string $engine) => array_key_exists($engine, $installedTags))
            ->map(fn (string $engine) => (string) str($engine)->headline())
            ->values();
        $cachesDetail = match (true) {
            $cacheRows->isNotEmpty() => $cacheCounts->map(fn ($n, $engine) => trans_choice(':n :engine instance|:n :engine instances', $n, ['n' => $n, 'engine' => $engine]))->implode(' · '),
            $cacheTagLabels->isNotEmpty() => $cacheTagLabels->implode(' · '),
            default => __('No instances yet'),
        };

        return collect([
            [
                'key' => 'webserver',
                'label' => __('Webserver'),
                'icon' => 'heroicon-o-globe-alt',
                'href' => route('servers.webserver', $server),
                'detail' => ucfirst($webserverActive).' — '.__('switch + service actions'),
                'shown' => true,
            ],
            [
                'key' => 'php',
                'label' => __('PHP'),
                'icon' => 'heroicon-o-command-line',
                'href' => route('servers.php', $server),
                'detail' => $phpDetail,
                'shown' => $phpInstalled,
            ],
            [
                'key' => 'caches',
                'label' => __('Caches'),
                'icon' => 'heroicon-o-bolt',
                'href' => route('servers.caches', $server),
                'detail' => $cachesDetail,
                'shown' => true,
            ],
            [
                'key' => 'databases',
                'label' => __('Databases'),
                'icon' => 'heroicon-o-circle-stack',
                'href' => route('servers.databases', $server),
                'detail' => $databasesDetail,
                'shown' => $databasesShown,
            ],
            [
                'key' => 'daemons',
                'label' => __('Daemons'),
                'icon' => 'heroicon-o-server-stack',
                'href' => route('servers.daemons', $server),
                'detail' => __('Supervisor-managed processes'),
                'shown' => array_key_exists('supervisor', $installedTags),
            ],
            [
                'key' => 'cron',
                'label' => __('Cron jobs'),
                'icon' => 'heroicon-o-clock',
                'href' => route('servers.cron', $server),
                'detail' => __('Scheduled tasks'),
                'shown' => true,
            ],
        ])->filter(fn ($t) => $t['shown'])->values();
    }
}
