<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Jobs\CollectWorkerPoolStatsJob;
use App\Models\Concerns\Site\HasSiteRelationships;
use App\Models\ConsoleAction;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\WorkerPool;
use App\Services\WorkerPools\SiteWorkerFleetPreflight;
use App\Services\WorkerPools\WorkerBootImage;
use App\Services\WorkerPools\WorkerCloneProvisioner;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Services\WorkerPools\WorkerProvisionProgress;
use App\Support\Providers\ProviderAuthFailure;
use App\Support\Sites\SiteWorkerFleetSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteWorkerPool
{
    /** Optional custom HTML for the worker page; empty = built-in dply page. */
    public string $worker_page_html = '';

    public bool $showAddWorkerModal = false;

    public string $addWorkerSize = '';

    public string $addWorkerRegion = '';

    public string $addWorkerSiteRegion = '';

    public string $addWorkerRegionLabel = '';

    /** @var list<array{value: string, label: string}> */
    public array $addWorkerRegions = [];

    public bool $addWorkerAllowsRemoteRegion = false;

    /** @var list<array{value: string, label: string, memory_mb?: int|null, vcpus?: int|null, disk_gb?: int|null, price_monthly?: float|null}> */
    public array $addWorkerSizes = [];

    public bool $addWorkerCatalogLoading = false;

    public ?string $addWorkerCatalogError = null;

    public bool $addWorkerStopOnBox = true;

    /** @var array{ok: bool, message: string, backends: list<array{id: string, name: string, role: string}>, networkName: string|null}|null */
    public ?array $addWorkerPreflight = null;

    public bool $showDestroyFleetModal = false;

    public string $destroyFleetPoolId = '';

    public bool $destroyFleetRestoreOnBox = true;

    public bool $showWorkerProcessModal = false;

    public string $workerProcessMemberId = '';

    /**
     * Resolve a worker pool that's actually attached to this site (workspace-
     * scoped), so the site Workers panel can only act on its own fleet.
     */
    private function resolveAttachedPool(string $poolId): ?WorkerPool
    {
        return $this->site->attachedWorkerPools()->firstWhere('id', $poolId);
    }

    /**
     * @return list<Model>
     */
    protected function additionalConsoleActionSubjects(): array
    {
        return $this->site->attachedWorkerPools()
            ->flatMap(fn (WorkerPool $pool) => $pool->servers)
            ->filter()
            ->unique(fn (Server $server): string => (string) $server->id)
            ->values()
            ->all();
    }

    public function fleetScaleRun(): ?ConsoleAction
    {
        foreach ($this->site->attachedWorkerPools() as $pool) {
            $primary = $pool->primaryServer;
            if (! $primary instanceof Server) {
                continue;
            }

            $run = ConsoleAction::query()
                ->where('subject_type', $primary->getMorphClass())
                ->where('subject_id', $primary->getKey())
                ->where('kind', 'worker_pool_scale')
                ->whereNull('dismissed_at')
                ->orderByDesc('created_at')
                ->first();
            if ($run instanceof ConsoleAction) {
                return $run;
            }
        }

        return null;
    }

    /**
     * Stored token health for the banner on Worker servers — no live API call.
     *
     * @return array{name: string, message: string}|null
     */
    public function fleetIsInstalling(): bool
    {
        foreach ($this->site->attachedWorkerPools() as $pool) {
            foreach ($pool->servers as $member) {
                if ($member->status === Server::STATUS_ERROR
                    || $member->poolMemberState() === WorkerPool::MEMBER_ERRORED) {
                    continue;
                }
                if (! $member->isProvisioningComplete()) {
                    return true;
                }
                $state = $member->poolMemberState();
                if (in_array($state, [
                    WorkerPool::MEMBER_PROVISIONING,
                    WorkerPool::MEMBER_REPLAYING,
                    WorkerPool::MEMBER_DEPLOYING,
                    WorkerPool::MEMBER_DRAINING,
                ], true)) {
                    return true;
                }
            }
        }

        if ($this->fleetScaleRun()?->isInFlight() === true) {
            return true;
        }

        return ($this->workerBootImageNote()['state'] ?? null) === 'creating';
    }

    /**
     * @return array{state: string, title: string, message: string, name: ?string, region: ?string, when: ?string}|null
     */
    public function workerBootImageNote(): ?array
    {
        $images = app(WorkerBootImage::class);
        $ready = null;
        $hasMembers = false;

        foreach ($this->site->attachedWorkerPools() as $pool) {
            foreach ($pool->servers as $member) {
                $hasMembers = true;
                $note = $images->noteFor($member);
                if ($note === null) {
                    continue;
                }
                if (in_array($note['state'] ?? '', ['creating', 'failed'], true)) {
                    return $note;
                }
                $ready = $note;
            }
        }

        if ($ready === null && $this->site->server instanceof Server) {
            $ready = $images->noteFor($this->site->server);
        }

        if ($ready !== null) {
            return $ready;
        }

        if (! $hasMembers) {
            return null;
        }

        return [
            'state' => 'waiting',
            'title' => __('Saved stack image'),
            'message' => __('After the first worker finishes setup we snapshot the box (before the site is copied). The next worker boots from that image.'),
            'name' => null,
            'region' => $this->site->server?->region,
            'when' => null,
        ];
    }

    /**
     * @return array{name: string, message: string}|null
     */
    public function fleetCredentialAlert(): ?array
    {
        $server = $this->site->server;
        if (! $server instanceof Server) {
            return null;
        }

        $credential = ProviderCredential::preferredForServer($server);
        if ($credential === null || ! $credential->isUnhealthy()) {
            return null;
        }

        $raw = trim((string) $credential->validation_error);

        return [
            'name' => filled($credential->name) ? (string) $credential->name : $server->provider->label(),
            'message' => ProviderAuthFailure::detected($raw)
                ? ProviderAuthFailure::message($server->provider->value)
                : ($raw !== '' ? $raw : __('This provider token can no longer connect.')),
        ];
    }

    /**
     * @return array{label: string, detail: string, step: int, of: int}|null
     */
    public function workerProvisionProgress(Server $member): ?array
    {
        return app(WorkerProvisionProgress::class)->for($member);
    }

    public function openWorkerProcessModal(string $memberId): void
    {
        $this->authorize('view', $this->site);
        if ($this->resolveFleetMember($memberId) === null) {
            $this->toastError(__('That worker is not part of this site.'));

            return;
        }

        $this->workerProcessMemberId = $memberId;
        $this->showWorkerProcessModal = true;
    }

    public function closeWorkerProcessModal(): void
    {
        $this->showWorkerProcessModal = false;
        $this->workerProcessMemberId = '';
    }

    public function workerProcessMember(): ?Server
    {
        return $this->resolveFleetMember($this->workerProcessMemberId);
    }

    public function retryFailedWorkerProvision(string $memberId, WorkerCloneProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        $member = $this->resolveFleetMember($memberId);
        if (! $member instanceof Server) {
            $this->toastError(__('That worker is not part of this site.'));

            return;
        }

        try {
            $provisioner->retryCloudProvision($member);
        } catch (RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Retrying worker install — DigitalOcean will create the droplet once the token is accepted.'));
    }

    /**
     * @return array{message: string, authFailed: bool, provider: string}
     */
    public function workerProvisionFailure(Server $member): array
    {
        $raw = trim((string) data_get($member->meta, 'provision_error.message'));
        $provider = trim((string) data_get($member->meta, 'provision_error.provider', $member->provider->value));
        $authFailed = ProviderAuthFailure::detected($raw);

        return [
            'message' => $authFailed ? ProviderAuthFailure::message($provider) : ($raw !== '' ? $raw : __('Provisioning failed.')),
            'authFailed' => $authFailed,
            'provider' => $provider,
        ];
    }

    public function workerProcessDeployment(): ?SiteDeployment
    {
        $member = $this->workerProcessMember();
        if (! $member instanceof Server) {
            return null;
        }

        $replica = $this->site->fleetReplicaSites()
            ->where('server_id', $member->id)
            ->first();
        if (! $replica instanceof Site) {
            return null;
        }

        return $replica->deployments()->latest('created_at')->first();
    }

    private function resolveFleetMember(string $memberId): ?Server
    {
        $memberId = trim($memberId);
        if ($memberId === '') {
            return null;
        }

        foreach ($this->site->attachedWorkerPools() as $pool) {
            $member = $pool->servers->firstWhere('id', $memberId);
            if ($member instanceof Server) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Explicitly attach an existing org worker pool to this site. Once any pool
     * is explicitly attached, the explicit set fully defines the site's workers
     * (see {@see HasSiteRelationships::attachedWorkerPools()}).
     */
    public function openAddWorkerModal(): void
    {
        $this->authorize('update', $this->site);

        $server = $this->site->server;
        $this->addWorkerRegion = trim((string) ($server?->region ?? ''));
        $this->addWorkerSiteRegion = $this->addWorkerRegion;
        $this->addWorkerRegionLabel = $this->addWorkerRegion !== '' ? $this->addWorkerRegion : __('This site’s region');
        $this->addWorkerRegions = [];
        $this->addWorkerSize = $server ? SiteWorkerFleetSize::defaultFor($server) : '';
        $this->addWorkerSizes = [];
        $this->addWorkerCatalogError = null;
        $this->addWorkerCatalogLoading = true;
        $this->addWorkerStopOnBox = true;

        $result = app(SiteWorkerFleetPreflight::class)->evaluate($this->site);
        $this->addWorkerAllowsRemoteRegion = $result->allowsRemoteRegion;
        $this->addWorkerPreflight = [
            'ok' => $result->ok,
            'message' => $result->message,
            'backends' => $result->backends,
            'networkName' => $result->networkName,
            'allowsRemoteRegion' => $result->allowsRemoteRegion,
        ];

        $this->showAddWorkerModal = true;
    }

    public function loadAddWorkerCatalog(SiteWorkerFleetPreflight $preflight): void
    {
        $this->authorize('update', $this->site);

        $result = $preflight->evaluate($this->site);
        $this->addWorkerPreflight = [
            'ok' => $result->ok,
            'message' => $result->message,
            'backends' => $result->backends,
            'networkName' => $result->networkName,
            'allowsRemoteRegion' => $result->allowsRemoteRegion,
        ];
        $this->addWorkerAllowsRemoteRegion = $result->allowsRemoteRegion;

        $server = $this->site->server;
        $org = $this->site->organization ?? $server?->organization;
        $credential = $server instanceof Server ? ProviderCredential::preferredForServer($server) : null;
        if ($server === null || $org === null || $credential === null) {
            $this->addWorkerCatalogLoading = false;
            $this->addWorkerCatalogError = __('This server has no provider credential to list sizes.');

            return;
        }

        try {
            $catalog = ResolveServerCreateCatalog::run(
                $org,
                $server->provider->value,
                (string) $credential->id,
                $this->addWorkerRegion,
                true,
            );
        } catch (\Throwable $e) {
            $this->addWorkerCatalogLoading = false;
            $this->addWorkerCatalogError = ProviderAuthFailure::detected($e->getMessage())
                ? ProviderAuthFailure::message($server->provider->value)
                : $e->getMessage();

            return;
        }

        $regions = [];
        foreach (is_array($catalog['regions'] ?? null) ? $catalog['regions'] : [] as $region) {
            $value = trim((string) ($region['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $label = trim((string) ($region['label'] ?? $value));
            $regions[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
            if ($value === $this->addWorkerRegion && $label !== '') {
                $this->addWorkerRegionLabel = $label;
            }
        }
        $this->addWorkerRegions = $regions;

        $sizes = [];
        foreach (is_array($catalog['sizes'] ?? null) ? $catalog['sizes'] : [] as $size) {
            $value = trim((string) ($size['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $sizes[] = [
                'value' => $value,
                'label' => (string) ($size['label'] ?? $value),
                'memory_mb' => isset($size['memory_mb']) && is_numeric($size['memory_mb']) ? (int) $size['memory_mb'] : null,
                'vcpus' => isset($size['vcpus']) && is_numeric($size['vcpus']) ? (int) $size['vcpus'] : null,
                'disk_gb' => isset($size['disk_gb']) && is_numeric($size['disk_gb']) ? (int) $size['disk_gb'] : null,
                'price_monthly' => isset($size['price_monthly']) && is_numeric($size['price_monthly']) ? (float) $size['price_monthly'] : null,
            ];
        }

        $this->addWorkerSizes = $sizes;
        $catalogError = isset($catalog['error']) && is_string($catalog['error']) && $catalog['error'] !== ''
            ? $catalog['error']
            : null;
        $this->addWorkerCatalogError = $catalogError !== null && ProviderAuthFailure::detected($catalogError)
            ? ProviderAuthFailure::message($server->provider->value)
            : $catalogError;

        if ($sizes !== [] && ! collect($sizes)->contains(fn (array $size): bool => $size['value'] === $this->addWorkerSize)) {
            $this->addWorkerSize = $sizes[0]['value'];
        }

        $this->addWorkerCatalogLoading = false;
    }

    public function selectAddWorkerRegion(string $region): void
    {
        $this->authorize('update', $this->site);

        $region = trim($region);
        if ($region === '' || $region === $this->addWorkerRegion) {
            return;
        }
        if (! $this->addWorkerAllowsRemoteRegion && $region !== $this->addWorkerSiteRegion) {
            return;
        }

        $this->addWorkerRegion = $region;
        $this->addWorkerRegionLabel = $region;
        $this->addWorkerCatalogLoading = true;
        $this->loadAddWorkerCatalog(app(SiteWorkerFleetPreflight::class));
    }

    public function closeAddWorkerModal(): void
    {
        $this->showAddWorkerModal = false;
        $this->addWorkerCatalogLoading = false;
    }

    public function confirmAddWorker(WorkerPoolManager $manager): void
    {
        $this->authorize('update', $this->site);

        $this->validate([
            'addWorkerSize' => ['required', 'string', 'max:64'],
            'addWorkerRegion' => ['required', 'string', 'max:64'],
        ]);

        try {
            $manager->createPoolFromSite(
                auth()->user(),
                $this->site,
                trim($this->addWorkerSize),
                $this->addWorkerStopOnBox,
                trim($this->addWorkerRegion),
            );
        } catch (RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->showAddWorkerModal = false;
        $sameRegion = trim($this->addWorkerRegion) === $this->addWorkerSiteRegion;
        $this->toastSuccess($sameRegion
            ? __('Provisioning a worker for this site — it will join the same private network and deploy this release.')
            : __('Provisioning a worker in :region — we’ll allow its public IP on the managed Redis/database.', [
                'region' => $this->addWorkerRegionLabel !== '' ? $this->addWorkerRegionLabel : $this->addWorkerRegion,
            ]));
    }

    public function openDestroyFleetModal(string $poolId): void
    {
        $this->authorize('update', $this->site);
        $pool = $this->resolveAttachedPool($poolId);
        if ($pool === null || ! $pool->isSiteSourced()) {
            $this->toastError(__('Those workers cannot be destroyed from here.'));

            return;
        }

        $this->destroyFleetPoolId = $poolId;
        $this->destroyFleetRestoreOnBox = true;
        $this->showDestroyFleetModal = true;
    }

    public function closeDestroyFleetModal(): void
    {
        $this->showDestroyFleetModal = false;
        $this->destroyFleetPoolId = '';
    }

    public function confirmDestroyFleet(WorkerPoolManager $manager): void
    {
        $this->authorize('update', $this->site);
        $pool = $this->resolveAttachedPool($this->destroyFleetPoolId);
        if ($pool === null || ! $pool->isSiteSourced()) {
            $this->toastError(__('Workers not found for this site.'));

            return;
        }

        try {
            $manager->dissolveSiteSourcedPool($pool, $this->destroyFleetRestoreOnBox, auth()->user());
        } catch (RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->closeDestroyFleetModal();
        $this->toastSuccess(__('Draining and destroying the workers.'));
    }

    public function requestAttachWorkerPool(string $poolId): void
    {
        $this->authorize('update', $this->site);

        $pool = WorkerPool::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($poolId)
            ->first();

        if ($pool === null) {
            $this->toastError(__('Worker pool not found in this organization.'));

            return;
        }

        $crossServer = $pool->source_server_id !== null && $pool->source_server_id !== $this->site->server_id;
        if (! $crossServer) {
            $this->attachWorkerPool($poolId);

            return;
        }

        $this->openConfirmActionModal(
            'attachWorkerPool',
            [$poolId],
            __('Attach worker pool'),
            __('“:name” runs a different server’s code and queues (source: :src). It will only process this site’s background jobs if they share the same queue connection/Redis. Attach anyway?', [
                'name' => $pool->name ?: __('This pool'),
                'src' => $pool->sourceServer?->name ?? __('another server'),
            ]),
            __('Attach'),
            false,
        );
    }

    public function attachWorkerPool(string $poolId): void
    {
        $this->authorize('update', $this->site);

        $pool = WorkerPool::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereKey($poolId)
            ->first();

        if ($pool === null) {
            $this->toastError(__('Worker pool not found in this organization.'));

            return;
        }

        $this->site->workerPools()->syncWithoutDetaching([$pool->id]);

        // Reinforce the UI confirm server-side: a pool whose source server isn't
        // this site's box only drains the site's jobs if they share queues/Redis.
        $crossServer = $pool->source_server_id !== null && $pool->source_server_id !== $this->site->server_id;
        if ($crossServer) {
            $this->toastWarning(__('Attached :name — note it runs a different server’s code/queues, so it only processes this site’s jobs if they share the same queue connection/Redis.', ['name' => $pool->name ?: __('worker pool')]));

            return;
        }

        $this->toastSuccess(__('Attached :name to this site.', ['name' => $pool->name ?: __('worker pool')]));
    }

    /** Detach an explicitly-attached worker pool from this site (does not delete the pool). */
    public function detachWorkerPool(string $poolId): void
    {
        $this->authorize('update', $this->site);

        $this->site->workerPools()->detach($poolId);
        $this->toastSuccess(__('Detached the worker pool from this site.'));
    }

    public function requestDetachWorkerPool(string $poolId): void
    {
        $this->openConfirmActionModal(
            'detachWorkerPool',
            [$poolId],
            __('Detach worker pool'),
            __('Detach this worker pool from the site? The pool keeps running; it just stops being listed here.'),
            __('Detach'),
            false,
        );
    }

    /** Scale an attached worker pool to N members (declarative — reconciler converges). */
    public function scaleWorkerPool(string $poolId, int $count, WorkerPoolManager $manager): void
    {
        $this->authorize('update', $this->site);
        $pool = $this->resolveAttachedPool($poolId);
        if ($pool === null) {
            $this->toastError(__('Worker pool not found for this site.'));

            return;
        }
        $cap = (int) ($pool->max_size ?: 50);
        $count = max(1, min($count, $cap));
        $manager->setDesiredCount($pool, $count);
        $this->toastSuccess(__('Scaling workers to :n — provisioning/draining in the background.', ['n' => $count]));
    }

    /**
     * Refresh the pool's live workload: per-member worker-process counts (the
     * "distribution" — it's a pull queue, so each worker's share = its running
     * processes ÷ the pool's) plus the pool-wide Horizon backlog / throughput.
     * Both probes are QUEUED SSH jobs (never inline — see the no-render-path-SSH
     * rule) that stash results on member/pool meta, which the panel then reads.
     */
    public function refreshWorkerStats(string $poolId): void
    {
        $this->authorize('update', $this->site);
        $pool = $this->resolveAttachedPool($poolId);
        if ($pool === null) {
            $this->toastError(__('Worker pool not found for this site.'));

            return;
        }
        CollectWorkerPoolStatsJob::dispatch((string) $pool->id);
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id);
        $this->toastSuccess(__('Refreshing worker stats over SSH — numbers update in a few seconds.'));
    }

    public function fleetHasReadyWorkers(): bool
    {
        foreach ($this->site->attachedWorkerPools() as $pool) {
            foreach ($pool->servers as $member) {
                if ($this->memberIsReadyForWork($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function pollFleetWork(): void
    {
        $this->authorize('view', $this->site);
        $this->refreshFleetWorkIfStale();
    }

    /**
     * Pull Horizon + per-box process stats once a worker is healthy. Queued
     * SSH only — never on the render path.
     */
    public function refreshFleetWorkIfStale(): void
    {
        $this->authorize('view', $this->site);

        foreach ($this->site->attachedWorkerPools() as $pool) {
            $ready = $pool->servers->contains(fn (Server $member): bool => $this->memberIsReadyForWork($member));
            if (! $ready) {
                continue;
            }

            $hz = is_array($pool->meta['horizon'] ?? null) ? $pool->meta['horizon'] : [];
            $last = ! empty($hz['last_attempt_at'])
                ? Carbon::parse((string) $hz['last_attempt_at'])
                : (! empty($hz['collected_at']) ? Carbon::parse((string) $hz['collected_at']) : null);

            if ($last === null || $last->lte(now()->subSeconds(20))) {
                $hz['last_attempt_at'] = now()->toIso8601String();
                $meta = is_array($pool->meta) ? $pool->meta : [];
                $meta['horizon'] = $hz;
                $pool->forceFill(['meta' => $meta])->save();
                CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id);
            }

            $this->refreshMemberStatsIfStale($pool);
        }
    }

    private function refreshMemberStatsIfStale(WorkerPool $pool): void
    {
        $newest = $pool->servers
            ->map(fn (Server $member) => data_get($member->meta, 'pool.stats.collected_at'))
            ->filter()
            ->map(fn ($at) => Carbon::parse((string) $at))
            ->sort()
            ->last();

        if ($newest instanceof Carbon && $newest->gt(now()->subSeconds(45))) {
            return;
        }

        CollectWorkerPoolStatsJob::dispatch((string) $pool->id);
    }

    private function memberIsReadyForWork(Server $member): bool
    {
        return $member->isProvisioningComplete()
            && $member->status !== Server::STATUS_ERROR
            && $member->poolMemberState() !== WorkerPool::MEMBER_ERRORED;
    }

    /** Add one worker to an attached pool. */
    public function addPoolWorker(string $poolId, WorkerPoolManager $manager): void
    {
        $this->authorize('update', $this->site);
        $pool = $this->resolveAttachedPool($poolId);
        if ($pool === null) {
            $this->toastError(__('Worker pool not found for this site.'));

            return;
        }
        $next = (int) $pool->servers()->count() + 1;
        $cap = (int) ($pool->max_size ?: 50);
        if ($next > $cap) {
            $this->toastError(__('Pool is at its max size (:n).', ['n' => $cap]));

            return;
        }
        $manager->setDesiredCount($pool, $next);
        $this->toastSuccess(__('Adding a worker — provisioning in the background.'));
    }

    /** Drain + remove a specific (non-primary) worker from an attached pool. */
    public function removePoolWorker(string $poolId, string $serverId, WorkerPoolManager $manager): void
    {
        $this->authorize('update', $this->site);
        $pool = $this->resolveAttachedPool($poolId);
        if ($pool === null) {
            $this->toastError(__('Worker pool not found for this site.'));

            return;
        }
        $server = $pool->servers()->whereKey($serverId)->first();
        if ($server === null) {
            $this->toastError(__('That worker is not part of this pool.'));

            return;
        }
        if ($server->isPoolPrimary()) {
            $this->toastError(__('Can’t remove the primary worker — promote another from the pool page first.'));

            return;
        }
        $manager->removeMember($pool, $server);
        // Lower the target so the reconciler doesn't immediately re-provision it.
        $pool->forceFill(['desired_count' => max(1, (int) $pool->desired_count - 1)])->save();
        $this->toastSuccess(__('Draining and removing the worker.'));
    }

    public function requestRemovePoolWorker(string $poolId, string $serverId): void
    {
        $this->openConfirmActionModal(
            'removePoolWorker',
            [$poolId, $serverId],
            __('Remove worker'),
            __('Drain and destroy this worker server? In-flight jobs finish first.'),
            __('Remove worker'),
            true,
        );
    }
}
