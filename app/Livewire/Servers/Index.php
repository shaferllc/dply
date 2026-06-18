<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\DeleteServerAction;
use App\Enums\ServerProvider;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\GuardsBilledDeploys;
use App\Livewire\Concerns\ManagesServerRemovalForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Insights\Services\OrganizationInsightsMetricsService;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\ProvisioningDigest;
use App\Support\Servers\ServerTags;
use App\Support\Sites\DeployConsoleRows;
use App\Support\Sites\SiteSyncPeers;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use DispatchesToastNotifications;
    use GuardsBilledDeploys;
    use ManagesServerRemovalForm;

    public string $search = '';

    public string $sort = 'created_at';

    /** @var string all|pending|provisioning|ready|error|disconnected */
    public string $statusFilter = '';

    public string $tagFilter = '';

    /** @var string list|grid */
    public string $viewMode = 'list';

    /** Bumped when Reverb pushes server updates so the list re-queries from the database. */
    public int $serverListEpoch = 0;

    public ?string $deleteModalServerId = null;

    public string $deleteConfirmName = '';

    /** now|scheduled */
    public string $removeMode = 'now';

    public string $scheduledRemovalDate = '';

    /** Site ids launched from a fleet card, driving the global deploy console. */
    public array $watchedSiteIds = [];

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sort = 'created_at';
        $this->statusFilter = '';
        $this->tagFilter = '';
        $this->viewMode = 'list';
    }

    public bool $showDiscardServerCreateDraftModal = false;

    public function openDiscardServerCreateDraftModal(): void
    {
        $this->showDiscardServerCreateDraftModal = true;
    }

    public function closeDiscardServerCreateDraftModal(): void
    {
        $this->showDiscardServerCreateDraftModal = false;
    }

    public function confirmDiscardServerCreateDraft(): void
    {
        $org = auth()->user()?->currentOrganization();
        $draft = ServerCreateDraft::forCurrentScope(auth()->user(), $org);
        $draft?->delete();
        $this->showDiscardServerCreateDraftModal = false;
    }

    #[On('server-state-updated')]
    public function onServerStateUpdated(string $organizationId): void
    {
        $org = auth()->user()->currentOrganization();
        if (! $org || $org->id !== $organizationId) {
            return;
        }

        $this->serverListEpoch++;
    }

    public function openRemoveServerModal(string $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);
        $this->authorize('delete', $server);
        $this->deleteModalServerId = $serverId;
        $this->deleteConfirmName = '';
        $this->removeMode = 'now';
        $defaultDays = (int) config('dply.server_scheduled_deletion_default_days', 7);
        $this->scheduledRemovalDate = now()->addDays($defaultDays)->toDateString();
        $this->resetServerRemovalFormFields();
        $this->resetValidation();
    }

    public function closeRemoveServerModal(): void
    {
        $this->deleteModalServerId = null;
        $this->deleteConfirmName = '';
        $this->removeMode = 'now';
        $this->scheduledRemovalDate = '';
        $this->resetServerRemovalFormFields();
        $this->resetValidation();
    }

    public function submitRemoveServer(DeleteServerAction $deleteServer): void
    {
        if ($this->deleteModalServerId === null) {
            return;
        }

        $server = Server::query()->findOrFail($this->deleteModalServerId);
        $this->authorize('delete', $server);

        // Type-to-confirm — required for every mode. Mirrors the workspace
        // page's HandlesServerRemovalFlow behaviour.
        if (trim($this->deleteConfirmName) !== $server->name) {
            $this->addError('deleteConfirmName', __('Type the server name exactly to confirm.'));

            return;
        }

        // "In 30 min" mode — stamp scheduled_deletion_at to now+30 so the
        // every-minute scheduler picks it up. Operator can cancel from the
        // workspace page anytime in the window.
        if ($this->removeMode === 'in_30') {
            $reason = trim($this->deletionReason);
            $at = now()->addMinutes(30);
            $this->writeScheduledRemoval($server, $at, $reason !== '' ? $reason : null);
            $this->closeRemoveServerModal();
            $this->serverListEpoch++;
            $this->toastSuccess(__(':name will be removed in 30 minutes. Cancel from the workspace page anytime before that.', [
                'name' => $server->name,
            ]));

            return;
        }

        if ($this->removeMode === 'scheduled') {
            $this->validate([
                'scheduledRemovalDate' => ['required', 'date'],
                'deletionReason' => ['nullable', 'string', 'max:2000'],
            ]);
            $at = Carbon::parse($this->scheduledRemovalDate, config('app.timezone'))->endOfDay();
            if ($at->lte(now())) {
                $this->addError('scheduledRemovalDate', __('Pick a date whose end is still in the future (app timezone).'));

                return;
            }

            $reason = trim($this->deletionReason);
            $this->writeScheduledRemoval($server, $at, $reason !== '' ? $reason : null);
            $this->closeRemoveServerModal();
            $this->serverListEpoch++;
            $this->toastSuccess(__(':name is scheduled for removal at the end of :date.', [
                'name' => $server->name,
                'date' => $at->toFormattedDateString(),
            ]));

            return;
        }

        if (ServerRemovalAdvisor::hasRunningDeployments($server)) {
            $this->addError('removeMode', __('Finish or cancel running deployments on this server\'s sites before removing it.'));

            return;
        }

        $summary = ServerRemovalAdvisor::summary($server);
        $rules = $this->immediateServerRemovalRules($summary);
        if ($rules !== []) {
            $this->validate($rules);
        }

        $reason = trim($this->deletionReason);
        $auditExtras = ['immediate' => true];
        if ($reason !== '') {
            $auditExtras['reason'] = $reason;
        }

        $actor = auth()->user();
        $emailContext = __('Removed by :name (:email) from the servers list.', [
            'name' => $actor->name,
            'email' => $actor->email,
        ]);

        $this->closeRemoveServerModal();
        $deleteServer->execute($server, $actor, $auditExtras, $emailContext);
        $this->serverListEpoch++;
        $this->toastSuccess(__('Server removed.'));
    }

    /**
     * Stamp scheduled_deletion_at + audit + notify. Shared between the
     * 30-minute grace mode and the date-picker mode so the two only differ
     * on the choice of $at.
     */
    private function writeScheduledRemoval(Server $server, Carbon $at, ?string $reason): void
    {
        $meta = $server->meta ?? [];
        if ($reason !== null && $reason !== '') {
            $meta['scheduled_deletion_reason'] = $reason;
        } else {
            unset($meta['scheduled_deletion_reason']);
        }

        $org = $server->organization;
        if ($org) {
            $auditNew = ['scheduled_deletion_at' => $at->toIso8601String()];
            if ($reason !== null && $reason !== '') {
                $auditNew['reason'] = $reason;
            }
            audit_log($org, auth()->user(), 'server.deletion_scheduled', $server, null, $auditNew);
        }

        $server->update([
            'scheduled_deletion_at' => $at,
            'meta' => $meta,
        ]);
        $this->notifyOrgAdminsOfScheduledRemoval($server->fresh(['organization']), $at, $reason);
    }

    public function cancelScheduledServerRemoval(string $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);
        $this->authorize('delete', $server);
        if ($server->scheduled_deletion_at === null) {
            return;
        }

        $org = $server->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'server.deletion_schedule_cancelled', $server, [
                'scheduled_deletion_at' => $server->scheduled_deletion_at->toIso8601String(),
            ], null);
        }

        $meta = $server->meta ?? [];
        unset($meta['scheduled_deletion_reason']);
        $server->update([
            'scheduled_deletion_at' => null,
            'meta' => $meta,
        ]);
        $this->serverListEpoch++;
        $this->toastSuccess(__('Scheduled removal was cancelled.'));
    }

    /**
     * Deploy a single site from its server card — the fleet twin of the deploy
     * sidebar's "Deploy" button. Seeds the same optimistic deploy lock and
     * dispatches the same job so both surfaces share one "is a deploy running"
     * source of truth.
     */
    public function deploySite(string $siteId): void
    {
        $site = Site::query()->with('server')->find($siteId);
        if ($site === null || ! $this->siteIsDeployable($site)) {
            return;
        }
        if ($this->blockedByDeployPause($site)) {
            return;
        }
        Gate::authorize('update', $site);

        $this->queueSiteDeploy($site);
        $this->watchDeploys([(string) $site->id]);
        $this->toastSuccess(__('Deployment queued for :name.', ['name' => $site->name]));
    }

    /**
     * Deploy a site together with its synced peers — the fleet twin of the
     * sidebar's "Sync" button. Peers are this site plus any sharing its Git
     * repository (or the same server when no repo is set), resolved by the same
     * {@see SiteSyncPeers} the sidebar uses.
     */
    public function deploySyncedSites(string $siteId): void
    {
        $site = Site::query()->with('server')->find($siteId);
        if ($site === null) {
            return;
        }
        if ($this->blockedByDeployPause($site)) {
            return;
        }

        [$queuedIds, $skipped] = $this->queueDeploys(SiteSyncPeers::forSite($site));
        $this->watchDeploys($queuedIds);
        $this->reportBatchDeploy(count($queuedIds), $skipped);
    }

    /** Deploy every deployable site on one server (multi-site card "Deploy all"). */
    public function deployServerSites(string $serverId): void
    {
        $server = Server::query()->with('sites')->find($serverId);
        if ($server === null) {
            return;
        }

        $deployable = $server->sites->filter(function (Site $site) use ($server): bool {
            $site->setRelation('server', $server);

            return $this->siteIsDeployable($site);
        });
        if ($deployable->isEmpty()) {
            return;
        }
        if ($this->blockedByDeployPause($deployable->first())) {
            return;
        }

        [$queuedIds, $skipped] = $this->queueDeploys($deployable);
        $this->watchDeploys($queuedIds);
        $this->reportBatchDeploy(count($queuedIds), $skipped);
    }

    /**
     * Queue deploys for an authorised, deployable subset of the given sites.
     *
     * @param  Collection<int, Site>  $sites
     * @return array{0:list<string>,1:int} [queuedSiteIds, skipped]
     */
    protected function queueDeploys(Collection $sites): array
    {
        $queuedIds = [];
        $skipped = 0;
        foreach ($sites as $site) {
            if (! $this->siteIsDeployable($site)) {
                $skipped++;

                continue;
            }
            $this->queueSiteDeploy($site);
            $queuedIds[] = (string) $site->id;
        }

        return [$queuedIds, $skipped];
    }

    /** Seed the optimistic deploy lock and dispatch the deployment job. */
    protected function queueSiteDeploy(Site $site): void
    {
        Cache::put('site-deploy-active:'.$site->id, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], 600);
        RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
    }

    /**
     * Point the global deploy console at the sites just launched and open it, so
     * a deploy kicked off from a fleet card can be watched live without leaving
     * the page. Mirrors DeployControl's `deploy-console-open` event wiring.
     *
     * @param  list<string>  $siteIds
     */
    protected function watchDeploys(array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        $this->watchedSiteIds = array_values(array_map('strval', $siteIds));
        unset($this->watchedRows, $this->watchedInProgress);
        $this->dispatch('deploy-console-open');
    }

    /**
     * Live per-site rows for the global deploy console — the sites launched from
     * a fleet card, with their phase timelines and in-flight state.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function watchedRows(): array
    {
        return DeployConsoleRows::forSiteIds($this->watchedSiteIds);
    }

    #[Computed]
    public function watchedInProgress(): bool
    {
        return DeployConsoleRows::anyInProgress($this->watchedRows);
    }

    protected function reportBatchDeploy(int $queued, int $skipped): void
    {
        if ($queued === 0) {
            $this->toastError(__('No deployable sites to queue.'));

            return;
        }

        $msg = trans_choice('{1}:count deployment queued.|[2,*]:count deployments queued.', $queued, ['count' => $queued]);
        if ($skipped > 0) {
            $msg .= ' '.__(':n skipped.', ['n' => $skipped]);
        }
        $this->toastSuccess($msg);
    }

    /**
     * Whether a site can be VM-deployed by the current user. Mirrors
     * {@see \App\Livewire\Sites\DeployControl::canDeploy()} — VM host, not a
     * functions/edge runtime, and the user may update it. Expects the site's
     * `server` relation to be loaded.
     */
    protected function siteIsDeployable(Site $site): bool
    {
        $server = $site->server;

        return $server !== null
            && $server->isVmHost()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesEdgeRuntime()
            && Gate::allows('update', $site);
    }

    /**
     * Per-server deploy targets for the fleet Deploy / Sync buttons. For each
     * server with at least one deployable site, returns the deployable sites, an
     * anchor (the first), and the anchor's sync-peer count for the "Sync N"
     * badge. Sync counts are derived from one org-wide pass over sites so the
     * fleet never does an N+1 of per-site peer queries.
     *
     * @param  Collection<int, Server>  $servers
     * @return array<int|string, array{sites: Collection<int, Site>, anchor: Site, sync_count: int}>
     */
    protected function buildDeployTargets(Collection $servers, ?Organization $org): array
    {
        if ($servers->isEmpty()) {
            return [];
        }

        // Org-wide repo → count, so a single-site server still shows "Sync N"
        // when its repository is deployed on other servers too.
        $repoCounts = collect();
        if ($org) {
            $repoCounts = Site::query()
                ->where('organization_id', $org->id)
                ->whereNotNull('git_repository_url')
                ->where('git_repository_url', '!=', '')
                ->pluck('git_repository_url')
                ->groupBy(fn (string $repo): string => trim($repo))
                ->map->count();
        }

        $targets = [];
        foreach ($servers as $server) {
            $deployable = $server->sites
                ->filter(function (Site $site) use ($server): bool {
                    $site->setRelation('server', $server);

                    return $this->siteIsDeployable($site);
                })
                ->values();

            if ($deployable->isEmpty()) {
                continue;
            }

            $anchor = $deployable->first();
            $repo = trim((string) $anchor->git_repository_url);
            $syncCount = $repo !== ''
                ? (int) ($repoCounts[$repo] ?? 1)
                : (int) $server->sites->count();

            $targets[$server->id] = [
                'sites' => $deployable,
                'anchor' => $anchor,
                'sync_count' => $syncCount,
            ];
        }

        return $targets;
    }

    /**
     * @return Collection<string, Collection<int, Server>>
     */
    protected function groupedServers(Collection $servers): Collection
    {
        return $servers
            ->groupBy(function (Server $server): string {
                if ($server->team_id !== null && $server->relationLoaded('team') && $server->team !== null) {
                    return $server->team->name;
                }
                if ($server->organization_id !== null && $server->relationLoaded('organization') && $server->organization !== null) {
                    return $server->organization->name;
                }

                return __('Personal');
            })
            ->sortKeys();
    }

    /**
     * Build the per-server "related servers" map for the fleet disclosure.
     * A peer is related when it shares this server's worker pool, private
     * network, or project (workspace). The reason shown is the tightest match,
     * in that priority order, so each peer appears once. Peers are drawn from
     * the full in-scope set so a server hidden by the active filter still links.
     *
     * @param  Collection<int, Server>  $servers   the rows actually rendered
     * @param  Collection<int, Server>  $candidates the full in-scope fleet
     * @return array<int|string, list<array{server: Server, reason: string}>>
     */
    protected function relatedServersMap(Collection $servers, Collection $candidates): array
    {
        $byPool = $candidates->filter(fn (Server $s) => $s->worker_pool_id !== null)->groupBy('worker_pool_id');
        $byNetwork = $candidates->filter(fn (Server $s) => $s->private_network_id !== null)->groupBy('private_network_id');
        $byProject = $candidates->filter(fn (Server $s) => $s->workspace_id !== null)->groupBy('workspace_id');

        $map = [];
        foreach ($servers as $server) {
            /** @var array<int|string, array{server: Server, reason: string}> $peers */
            $peers = [];

            // Tightest reason wins: a peer added under "same pool" is not
            // overwritten by a looser "same project" match later.
            $collect = function (?Collection $group, string $reason) use (&$peers, $server): void {
                foreach ($group ?? collect() as $candidate) {
                    if ($candidate->id === $server->id || isset($peers[$candidate->id])) {
                        continue;
                    }
                    $peers[$candidate->id] = ['server' => $candidate, 'reason' => $reason];
                }
            };

            if ($server->worker_pool_id !== null) {
                $collect($byPool->get($server->worker_pool_id), __('same pool'));
            }
            if ($server->private_network_id !== null) {
                $collect($byNetwork->get($server->private_network_id), __('same VPC'));
            }
            if ($server->workspace_id !== null) {
                $collect($byProject->get($server->workspace_id), __('same project'));
            }

            if ($peers !== []) {
                $map[$server->id] = array_values($peers);
            }
        }

        return $map;
    }

    protected function baseQuery(): ?Builder
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            return null;
        }

        $query = Server::query()
            ->where(function (Builder $q) use ($org) {
                $q->where('organization_id', $org->id)
                    ->orWhere(fn (Builder $q2) => $q2->whereNull('organization_id')->where('user_id', auth()->id()));
            });

        $team = auth()->user()->currentTeam();
        if ($team) {
            $query->where('team_id', $team->id);
        }

        return $query;
    }

    protected function applyFilters(Builder $query): Builder
    {
        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->orWhere('provider', 'like', $like);
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        $tag = trim($this->tagFilter);
        if ($tag !== '') {
            $query->whereJsonContains('meta->tags', $tag);
        }

        return match ($this->sort) {
            'name' => $query->orderBy('name'),
            'status' => $query->orderBy('status')->orderBy('name'),
            default => $query->orderByDesc('created_at'),
        };
    }

    public function render(OrganizationInsightsMetricsService $insightsMetrics): View
    {
        $base = $this->baseQuery();
        $org = auth()->user()->currentOrganization();
        $allInScope = $base !== null ? (clone $base)->get() : collect();
        $tagOptions = ServerTags::collectFromServers($allInScope);
        $hasServersInScope = $base !== null && $allInScope->isNotEmpty();
        $servers = $base
            ? $this->applyFilters(clone $base)
                ->with(['sites', 'organization', 'team', 'workspace', 'databaseEngines', 'cacheServices'])
                ->withCount('sites')
                ->get()
            : collect();

        $groupedServers = $this->groupedServers($servers);

        // Per-server Deploy / Sync targets for the fleet card action buttons.
        $deployTargets = $this->buildDeployTargets($servers, $org);

        // Per-server "related servers" map for the fleet disclosure: peers that
        // share a worker pool, private network, or project. Computed against the
        // full in-scope set ($allInScope) so a peer hidden by the current filter
        // still shows. Pure column compares on already-loaded rows — no queries.
        $relatedServers = $this->relatedServersMap($servers, $allInScope);

        // Insights is gated (coming soon). Skip the per-server rollup query
        // entirely when the flag is off — the fleet rows hide the badge too.
        $insightRollup = $servers->isNotEmpty() && Feature::active('workspace.insights')
            ? $insightsMetrics->perServerRollup($servers->pluck('id'))
            : collect();

        // Live metric pulse per server — latest CPU/Mem/Disk for fleet
        // glance. One distinct subquery joining the latest captured_at
        // per server, keyed by id for the blade.
        $latestSnapshots = collect();
        if ($servers->isNotEmpty()) {
            $serverIds = $servers->pluck('id')->all();
            $latestPerServer = ServerMetricSnapshot::query()
                ->whereIn('server_id', $serverIds)
                ->whereIn('id', function ($q) use ($serverIds): void {
                    $q->from('server_metric_snapshots')
                        ->selectRaw('MAX(id)')
                        ->whereIn('server_id', $serverIds)
                        ->groupBy('server_id');
                })
                ->get(['id', 'server_id', 'captured_at', 'payload']);
            $latestSnapshots = $latestPerServer->keyBy('server_id');
        }

        $summary = [
            'total' => $servers->count(),
            'ready' => $servers->where('status', Server::STATUS_READY)->count(),
            'attention' => $servers->filter(function (Server $server): bool {
                if ($server->scheduled_deletion_at !== null) {
                    return true;
                }

                if (in_array($server->status, [Server::STATUS_ERROR, Server::STATUS_DISCONNECTED], true)) {
                    return true;
                }

                return $server->status === Server::STATUS_READY
                    && $server->health_status === Server::HEALTH_UNREACHABLE;
            })->count(),
            'sites' => (int) $servers->sum('sites_count'),
        ];

        $openInsights = (int) $insightRollup->sum(fn (array $row): int => (int) ($row['open'] ?? 0));
        $hasProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;
        // Q19 onboarding empty state: surface per-source "Migrate from {X}" CTAs
        // alongside Create Server when matching inventory-import credentials are
        // connected for the current org. Keeps Ploi and Forge as separate buttons
        // so an empty page doesn't push a user toward a source they aren't on.
        $importSources = collect();
        if ($org) {
            $importSources = ProviderCredential::query()
                ->where('organization_id', $org->id)
                ->whereIn('provider', ServerProvider::importProviderKeys())
                ->pluck('provider')
                ->unique()
                ->values();
        }
        $hasImportCredentials = $importSources->isNotEmpty();

        $deleteModalServer = $this->deleteModalServerId
            ? Server::query()->find($this->deleteModalServerId)
            : null;
        $deletionSummary = $deleteModalServer
            ? ServerRemovalAdvisor::summary($deleteModalServer)
            : null;

        $serverCreateDraft = ServerCreateDraft::forCurrentScope(auth()->user(), $org);

        // Per-server "what's happening right now" digest. Returns null for
        // servers that aren't mid-provision; the blade only renders the
        // detail row when there's something to show.
        $provisioningDigests = $servers
            ->mapWithKeys(static fn (Server $server) => [$server->id => ProvisioningDigest::forServer($server)])
            ->filter();

        // Servers whose provision step flipped to failed. Surfaced as a
        // page-level banner above the fleet list so a stalled provision is
        // visible without scrolling — pairs with the per-card "Setup failed"
        // chip rendered by displayStatus().
        $failedSetups = $servers
            ->where('setup_status', Server::SETUP_STATUS_FAILED)
            ->values();

        return view('livewire.servers.index', [
            'hasServersInScope' => $hasServersInScope,
            'servers' => $servers,
            'groupedServers' => $groupedServers,
            'deployTargets' => $deployTargets,
            'relatedServers' => $relatedServers,
            'insightRollup' => $insightRollup,
            'latestSnapshots' => $latestSnapshots,
            'provisioningDigests' => $provisioningDigests,
            'failedSetups' => $failedSetups,
            'summary' => $summary,
            'openInsights' => $openInsights,
            'hasProviderCredentials' => $hasProviderCredentials,
            'hasImportCredentials' => $hasImportCredentials,
            'importSources' => $importSources,
            'deleteModalServer' => $deleteModalServer,
            'deletionSummary' => $deletionSummary,
            'serverCreateDraft' => $serverCreateDraft,
            'sortOptions' => config('user_preferences.server_sort_options', []),
            'statusOptions' => [
                '' => __('All statuses'),
                Server::STATUS_PENDING => __('Pending'),
                Server::STATUS_PROVISIONING => __('Provisioning'),
                Server::STATUS_READY => __('Ready'),
                Server::STATUS_ERROR => __('Error'),
                Server::STATUS_DISCONNECTED => __('Disconnected'),
            ],
            'tagOptions' => $tagOptions,
        ]);
    }
}
