<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\ServerCacheServiceReplication;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\CacheWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheView
{
    public function render(
        CacheServiceStats $statsService,
    ): View {
        // Engine capabilities + distro-support gates are SSH-probed off the render path via
        // wire:init (loadCacheCapabilities) so the workspace paints instantly; the per-engine
        // "running" badge and Install gate resolve once that returns. $capabilitiesLoaded gates
        // the "checking…" UI. Cached 24h, so this is usually a no-op after the first load.
        $capabilities = $this->capabilitiesLoaded
            ? ($this->capabilities_state ?: ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false])
            : ['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false];

        $engineUnsupportedReasons = $this->capabilitiesLoaded
            ? ($this->cache_unsupported_reasons ?: ['redis' => null, 'valkey' => null, 'memcached' => null, 'keydb' => null, 'dragonfly' => null])
            : ['redis' => null, 'valkey' => null, 'memcached' => null, 'keydb' => null, 'dragonfly' => null];

        $allowed = array_merge(['overview', 'advanced'], CacheServiceInstallScripts::supportedEngines());
        if (! in_array($this->workspace_tab, $allowed, true)) {
            $this->workspace_tab = 'overview';
        }

        // Drop any memo a pre-render busy-check populated so this render reads
        // rows live (incl. ones a mutating action just created), then share that
        // single fetch with every downstream consumer in this lifecycle.
        $this->cacheServicesMemo = null;
        $services = $this->cacheServices();

        // Pull live stats per-engine when looking at Overview and the engine is RUNNING. The
        // 30s cache inside the stats service keeps repeated renders cheap. With multi-instance,
        // stats are shown per (engine, instance-name) — the overview cards iterate over all
        // installed instances individually.
        $statsByInstance = [];
        if ($this->workspace_tab === 'overview') {
            foreach ($services as $row) {
                if ($row->status === ServerCacheService::STATUS_RUNNING) {
                    $statsByInstance[$row->engine][$row->name] = $statsService->snapshot($this->server, $row);
                }
            }
        }

        // One row per (server, engine) post-collapse — keyBy gives the per-engine row directly
        // so the view's foreach can grab `$cacheServicesByEngine->get($engine)` without the
        // legacy (engine, name) double-grouping the multi-instance era required.
        $primaryByEngine = $services->keyBy('engine');

        // Per-engine console-action runs. The blade renders the static banner whenever a
        // matching row exists; filtering by 'cache_' kind family keeps unrelated runs
        // (notification dispatches, audit replay) from leaking onto a cache banner.
        $cacheRunsByEngine = $primaryByEngine
            ->mapWithKeys(fn (ServerCacheService $row): array => [
                $row->engine => $this->latestConsoleActionFor($row, 'cache_'),
            ])
            ->filter()
            ->all();

        $auditEvents = ServerCacheServiceAuditEvent::query()
            ->where('server_id', $this->server->id)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(40)
            ->get();

        // Latest non-dismissed manage_action run for this server — drives the
        // Show Redis INFO output banner on the redis Stats subtab.
        $manageActionRun = ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        // Candidate replica servers for the Add-Replica modal: org-owned,
        // redis/valkey role, READY, and not yet replicating from another master.
        $availableReplicaServers = collect();
        $activeReplications = collect();
        if ($this->engine_subtab === 'stats') {
            $availableReplicaServers = Server::query()
                ->where('organization_id', $this->server->organization_id)
                ->where('id', '!=', $this->server->id)
                ->where('status', Server::STATUS_READY)
                ->where(function ($q): void {
                    $q->whereJsonContains('meta->server_role', 'redis')
                        ->orWhereJsonContains('meta->server_role', 'valkey');
                })
                ->orderBy('name')
                ->get();

            $masterEngine = $this->currentEngineTab();
            $masterRow = $masterEngine ? $this->cacheServiceFor($masterEngine) : null;
            if ($masterRow) {
                $activeReplications = ServerCacheServiceReplication::query()
                    ->where('master_cache_service_id', $masterRow->id)
                    ->with(['replicaCacheService.server'])
                    ->get();
            }
        }

        return view('livewire.servers.workspace-caches', array_merge(
            CacheWorkspaceViewData::for($this->server, $this, $services),
            [
                'capabilities' => $capabilities,
                'capabilitiesLoaded' => $this->capabilitiesLoaded,
                'engineUnsupportedReasons' => $engineUnsupportedReasons,
                'cacheServices' => $services,
                'cacheServicesByEngine' => $primaryByEngine,
                'cacheRunsByEngine' => $cacheRunsByEngine,
                'cacheStatsByInstance' => $statsByInstance,
                'cacheAuditEvents' => $auditEvents,
                // Allowlisted manage actions exposed on Caches (currently just `redis_info`).
                // Banner-only flow — see RunsAllowlistedManageAction.
                'serviceActions' => config('server_manage.service_actions', []),
                'manageActionRun' => $manageActionRun,
                'deletionSummary' => null,
                'availableReplicaServers' => $availableReplicaServers,
                'activeReplications' => $activeReplications,
            ],
        ));
    }

    protected function cacheServices(): Collection
    {
        return $this->cacheServicesMemo ??= ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->orderBy('engine')
            ->get();
    }

    /**
     * Sites consuming this server's cache services, grouped by cache-service id —
     * the "Used by" list on each engine's Overview. One query for the whole tab.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    #[Computed]
    public function cacheConsumers(): array
    {
        return $this->buildBindingConsumers(
            'server_cache_service',
            $this->cacheServices()->pluck('id')->all(),
            $this->server->id,
        );
    }

    /**
     * Look up the engine row on this server. With one-row-per-engine, this is the row every
     * per-engine action operates on. The `name` filter survives only to ignore any historical
     * non-default rows that the collapse migration somehow missed.
     */
    protected function cacheServiceFor(string $engine): ?ServerCacheService
    {
        return ServerCacheService::query()
            ->where('server_id', $this->server->id)
            ->where('engine', $engine)
            ->where('name', ServerCacheService::DEFAULT_INSTANCE_NAME)
            ->first();
    }

    /** The engine name when the operator is on a per-engine tab; null otherwise. */
    protected function currentEngineTab(): ?string
    {
        return in_array($this->workspace_tab, CacheServiceInstallScripts::supportedEngines(), true)
            ? $this->workspace_tab
            : null;
    }
}
