<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Jobs\ApplyWorkerPoolExposureJob;
use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Jobs\CollectWorkerPoolStatsJob;
use App\Jobs\PushWorkerPoolHorizonConfigJob;
use App\Jobs\ReconcileWorkerPoolJob;
use App\Jobs\RunWorkerPoolTestJobsJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\ConsoleAction;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerCloneProvisioner;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Support\WorkerPools\WorkerPoolHorizonConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Server workspace page for cloning + scaling a worker server as a Worker Pool.
 * Same-region v1: create a pool from a worker host, set a desired member count,
 * promote a member, or remove (drain + destroy) a replica.
 *
 * See doc/specs/worker-pools/02-specification.md.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceWorkerPool extends Component
{
    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use InteractsWithServerWorkspace;
    use RendersWorkspacePlaceholder;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    public string $pool_name = '';

    public int $desired_count = 1;

    // Cross-region add (Phase 2)
    public string $cr_provider = '';

    public string $cr_credential_id = '';

    public string $cr_region = '';

    public string $cr_size = '';

    public bool $cr_ack_secrets = false;

    /** Loaded region/size catalog for the chosen provider+credential (empty → text fallback). */
    public array $cr_regions = [];

    public array $cr_sizes = [];

    // Autoscale config
    public bool $as_enabled = false;

    public int $as_min = 1;

    public int $as_max = 5;

    public int $as_backlog = 100;

    // Horizon config (env-var driven; see WorkerPoolHorizonConfig).
    public string $hz_queues = 'default';

    public int $hz_min_processes = 1;

    public int $hz_max_processes = 4;

    public string $hz_balance = 'auto';

    public int $hz_memory = 128;

    public int $hz_timeout = 720;

    public int $hz_tries = 1;

    /** Process manager the pool's worker daemons run under: systemd | supervisor. */
    public string $hz_process_manager = WorkerPool::PM_SYSTEMD;

    /**
     * Live per-job feed, newest first, pushed over Reverb from the worker boxes
     * (see WorkerPoolJobEvent). Capped; this is a rolling window, not history.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $liveJobs = [];

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        $pool = $this->pool();
        if ($pool) {
            $this->desired_count = $pool->desired_count;
            $this->pool_name = $pool->name;
            $as = is_array($pool->meta['autoscale'] ?? null) ? $pool->meta['autoscale'] : [];
            $this->as_enabled = (bool) ($as['enabled'] ?? false);
            $this->as_min = (int) ($as['min'] ?? 1);
            $this->as_max = (int) ($as['max'] ?? min(5, $pool->max_size));
            $this->as_backlog = (int) ($as['per_worker_backlog'] ?? 100);

            $hz = WorkerPoolHorizonConfig::for($pool);
            $this->hz_queues = implode(', ', $hz['queues']);
            $this->hz_min_processes = $hz['min_processes'];
            $this->hz_max_processes = $hz['max_processes'];
            $this->hz_balance = $hz['balance'];
            $this->hz_memory = $hz['memory'];
            $this->hz_timeout = $hz['timeout'];
            $this->hz_tries = $hz['tries'];
            $this->hz_process_manager = $pool->processManager();
        } else {
            $this->pool_name = $server->name.' pool';
        }
        // Catalog (region/size dropdowns) is loaded lazily when the operator picks
        // a provider/credential — not on mount, to avoid a provider API call on
        // every page view. Until then the cross-region form uses text inputs.
    }

    public function updatedCrProvider(): void
    {
        // New provider → its credentials/regions/sizes differ; reset dependents.
        $this->reset('cr_credential_id', 'cr_region', 'cr_size');
        $this->loadCrCatalog();
    }

    public function updatedCrCredentialId(): void
    {
        $this->loadCrCatalog();
    }

    /**
     * Pull the region + size catalog for the effective provider/credential so the
     * cross-region form can show dropdowns. Best-effort: provider-API failures
     * leave the lists empty and the blade falls back to free-text inputs.
     */
    public function loadCrCatalog(): void
    {
        $sameProvider = $this->cr_provider === '' || $this->cr_provider === $this->server->provider->value;
        $provider = $this->cr_provider !== '' ? $this->cr_provider : $this->server->provider->value;
        $credId = $this->cr_credential_id !== ''
            ? $this->cr_credential_id
            : ($sameProvider ? (string) $this->server->provider_credential_id : '');

        if ($credId === '') {
            $this->cr_regions = [];
            $this->cr_sizes = [];

            return;
        }

        try {
            $catalog = ResolveServerCreateCatalog::run(
                $this->server->organization,
                $provider,
                $credId,
                $this->cr_region,
            );
            $this->cr_regions = $catalog['regions'] ?? [];
            $this->cr_sizes = $catalog['sizes'] ?? [];
        } catch (\Throwable) {
            $this->cr_regions = [];
            $this->cr_sizes = [];
        }
    }

    public function saveAutoscale(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $min = max(1, $this->as_min);
        $max = max($min, min($this->as_max, $pool->max_size));
        $meta = is_array($pool->meta) ? $pool->meta : [];
        $existing = is_array($meta['autoscale'] ?? null) ? $meta['autoscale'] : [];
        $meta['autoscale'] = array_merge($existing, [
            'enabled' => $this->as_enabled,
            'min' => $min,
            'max' => $max,
            'per_worker_backlog' => max(1, $this->as_backlog),
            'cooldown_seconds' => (int) ($existing['cooldown_seconds'] ?? 300),
        ]);
        $pool->forceFill(['meta' => $meta])->save();

        $this->as_min = $min;
        $this->as_max = $max;
        $this->toastSuccess($this->as_enabled
            ? __('Autoscaling enabled (:min–:max workers).', ['min' => $min, 'max' => $max])
            : __('Autoscaling disabled.'));
    }

    /**
     * Persist the pool's Horizon config to meta, then project it onto every
     * member box as HORIZON_* env vars and restart their workers.
     */
    public function saveHorizonConfig(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        // Validate queue tokens before saving. The FIRST queue becomes the
        // dispatch target (REDIS_QUEUE), so a malformed/empty value silently
        // breaks routing (this is how a typo'd queue once stuck the control plane).
        $queueTokens = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->hz_queues)),
            fn (string $q): bool => $q !== '',
        ));
        if ($queueTokens === []) {
            $this->toastError(__('Add at least one queue — the first is the dispatch target (REDIS_QUEUE).'));

            return;
        }
        foreach ($queueTokens as $q) {
            if (! preg_match('/^[A-Za-z0-9_:.\-]+$/', $q)) {
                $this->toastError(__('Invalid queue name “:q” — use letters, digits, and _ : . - only.', ['q' => $q]));

                return;
            }
        }

        $previousManager = $pool->processManager();
        $newManager = in_array($this->hz_process_manager, [WorkerPool::PM_SYSTEMD, WorkerPool::PM_SUPERVISOR], true)
            ? $this->hz_process_manager
            : WorkerPool::PM_SYSTEMD;

        $meta = is_array($pool->meta) ? $pool->meta : [];
        $meta['horizon_config'] = [
            'queues' => $this->hz_queues,
            'min_processes' => $this->hz_min_processes,
            'max_processes' => $this->hz_max_processes,
            'balance' => $this->hz_balance,
            'memory' => $this->hz_memory,
            'timeout' => $this->hz_timeout,
            'tries' => $this->hz_tries,
        ];
        $meta['process_manager'] = $newManager;
        $pool->forceFill(['meta' => $meta])->save();
        $this->hz_process_manager = $newManager;

        // Re-read through the normaliser so the form reflects the clamped/cleaned
        // values that were actually stored (and will be pushed to the boxes).
        $normalised = WorkerPoolHorizonConfig::for($pool->refresh());
        $this->hz_queues = implode(', ', $normalised['queues']);
        $this->hz_min_processes = $normalised['min_processes'];
        $this->hz_max_processes = $normalised['max_processes'];
        $this->hz_balance = $normalised['balance'];
        $this->hz_memory = $normalised['memory'];
        $this->hz_timeout = $normalised['timeout'];
        $this->hz_tries = $normalised['tries'];

        PushWorkerPoolHorizonConfigJob::dispatch((string) $pool->id);

        // Switching process manager re-provisions every member's worker daemons
        // under the new backend and tears down the old one (systemd⇄supervisor).
        if ($newManager !== $previousManager) {
            app(WorkerPoolManager::class)->ensureWorkersAcrossPool($pool->refresh(), auth()->user());
            $this->toastSuccess(__('Switching workers to :pm — re-provisioning daemons on each member over SSH.', [
                'pm' => $newManager === WorkerPool::PM_SUPERVISOR ? 'Supervisor' : 'systemd',
            ]));

            return;
        }

        $this->toastSuccess(__('Horizon config saved — applying to workers over SSH (they restart in a few seconds).'));
    }

    public function saveProcessManager(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $previousManager = $pool->processManager();
        $newManager = in_array($this->hz_process_manager, [WorkerPool::PM_SYSTEMD, WorkerPool::PM_SUPERVISOR], true)
            ? $this->hz_process_manager
            : WorkerPool::PM_SYSTEMD;

        if ($newManager === $previousManager) {
            $this->toastError(__('Already using :pm.', ['pm' => $newManager]));

            return;
        }

        $meta = is_array($pool->meta) ? $pool->meta : [];
        $meta['process_manager'] = $newManager;
        $pool->forceFill(['meta' => $meta])->save();
        $this->hz_process_manager = $newManager;

        $manager->ensureWorkersAcrossPool($pool->refresh(), auth()->user());

        $this->toastSuccess(__('Switching all members to :pm — re-provisioning worker daemons over SSH.', [
            'pm' => $newManager === WorkerPool::PM_SUPERVISOR ? 'Supervisor' : 'systemd',
        ]));
    }

    public function pool(): ?WorkerPool
    {
        $id = $this->server->worker_pool_id;

        return $id ? WorkerPool::query()->with('servers')->find($id) : null;
    }

    public function createPool(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        if (! $this->server->isWorkerHost()) {
            $this->toastError(__('Only worker servers can start a worker pool.'));

            return;
        }

        try {
            $pool = $manager->createPool(auth()->user(), $this->server->fresh(), trim($this->pool_name));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->desired_count = $pool->desired_count;
        $this->toastSuccess(__('Worker pool created. This server is the primary.'));
    }

    public function scale(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool to scale.'));

            return;
        }

        try {
            $manager->setDesiredCount($pool, (int) $this->desired_count);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Scaling to :n worker(s). Provisioning runs in the background.', ['n' => (int) $this->desired_count]));
    }

    /**
     * Refresh per-member host + worker + Redis stats over SSH (queued, never
     * inline) so the Traffic tab shows live numbers. Switches to the Traffic
     * tab so the operator sees the result land.
     */
    public function collectStats(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'traffic';
        CollectWorkerPoolStatsJob::dispatch((string) $pool->id);
        $this->toastSuccess(__('Refreshing worker stats over SSH — numbers update in a few seconds.'));
    }

    /**
     * Ensure the queue daemon (Horizon when the app has laravel/horizon, else
     * queue:work) is defined and running on every member — creating the worker
     * SiteProcess where missing and (re)writing/starting its systemd unit. The
     * per-member progress streams to each member's own systemd console banner.
     */
    public function ensureWorkers(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $result = $manager->ensureWorkersAcrossPool($pool, auth()->user());
        $this->toastSuccess(__('Ensuring :daemon on :n member(s) — units are being written and started in the background.', [
            'daemon' => $result['daemon'] === 'horizon' ? 'Horizon' : __('queue workers'),
            'n' => $result['members'],
        ]));
    }

    /**
     * Start / stop / restart the worker daemon (systemd) on one member box.
     * $action ∈ ensure | start | stop | restart.
     */
    public function controlMemberWorkers(string $serverId, string $action, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $allowed = ['ensure', 'start', 'stop', 'restart', 'check', 'horizon:pause', 'horizon:continue', 'horizon:terminate', 'horizon:snapshot', 'horizon:status'];
        $action = in_array($action, $allowed, true) ? $action : 'restart';

        $pool = $this->pool();
        $member = $pool?->servers->firstWhere('id', $serverId);
        if (! $pool || ! $member) {
            $this->toastError(__('Member not found.'));

            return;
        }

        if (! $manager->controlMemberWorkers($member, $action, auth()->user())) {
            $this->toastError(__('No app site on :name to run workers from.', ['name' => $member->name]));

            return;
        }

        $verb = [
            'ensure' => __('Ensuring workers'),
            'start' => __('Starting workers'),
            'stop' => __('Stopping workers'),
            'restart' => __('Restarting workers'),
            'horizon:pause' => __('Pausing Horizon'),
            'horizon:continue' => __('Resuming Horizon'),
            'horizon:terminate' => __('Restarting Horizon'),
            'horizon:snapshot' => __('Snapshotting Horizon metrics'),
            'horizon:status' => __('Checking Horizon status'),
            'check' => __('Checking worker backend'),
        ][$action] ?? __('Updating workers');
        $this->toastSuccess(__(':verb on :name — watch the console banner below for output.', ['verb' => $verb, 'name' => $member->name]));
    }

    /**
     * Pool-wide Horizon control: pause / continue / terminate (restart) /
     * snapshot, applied to every member's Horizon over SSH.
     */
    public function controlPoolHorizon(string $action, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $action = in_array($action, ['horizon:pause', 'horizon:continue', 'horizon:terminate', 'horizon:snapshot'], true)
            ? $action
            : 'horizon:snapshot';

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $n = 0;
        foreach ($pool->servers as $member) {
            if ($manager->controlMemberWorkers($member, $action, auth()->user())) {
                $n++;
            }
        }

        $verb = [
            'horizon:pause' => __('Pausing Horizon'),
            'horizon:continue' => __('Resuming Horizon'),
            'horizon:terminate' => __('Restarting Horizon'),
            'horizon:snapshot' => __('Snapshotting Horizon metrics'),
        ][$action];
        $this->toastSuccess(__(':verb on :n member(s).', ['verb' => $verb, 'n' => $n]));
    }

    /**
     * Retry / delete failed jobs on the pool's app over SSH. $uuid is a single
     * failed-job UUID, or null/'all' to act on every failed job.
     */
    public function retryFailedJob(?string $uuid = null, ?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:retry', $uuid, $manager);
    }

    public function forgetFailedJob(string $uuid, ?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:forget', $uuid, $manager);
    }

    public function retryAllFailed(?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:retry', 'all', $manager);
    }

    public function flushFailed(?WorkerPoolManager $manager = null): void
    {
        $this->queueFailedAction('queue:flush', null, $manager);
    }

    private function queueFailedAction(string $action, ?string $arg, ?WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);
        $manager ??= app(WorkerPoolManager::class);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        if (! $manager->controlPrimaryQueue($pool, $action, $arg, auth()->user())) {
            $this->toastError(__('No app site to manage failed jobs on.'));

            return;
        }

        $this->tab = 'horizon';
        $label = match ($action) {
            'queue:retry' => $arg && $arg !== 'all' ? __('Retrying failed job') : __('Retrying all failed jobs'),
            'queue:forget' => __('Deleting failed job'),
            'queue:flush' => __('Flushing all failed jobs'),
            default => __('Updating failed jobs'),
        };
        // Re-pull the snapshot shortly so the list reflects the change.
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id);
        $this->toastSuccess($label.' — '.__('the Horizon tab will refresh.'));
    }

    /**
     * Pull a Horizon-style metrics snapshot (failed/completed/pending, jobs per
     * minute, per-queue workload, recent failed jobs) from the app's Horizon
     * over SSH into the pool's Horizon tab.
     */
    public function refreshHorizon(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'horizon';
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id);
        $this->toastSuccess(__('Pulling Horizon metrics over SSH — the dashboard updates in a few seconds.'));
    }

    /**
     * Dispatch test jobs from the Horizon tab and auto-pull a fresh snapshot a
     * few seconds later, so the recent/pending lists below show whether Horizon
     * actually picked the jobs up. Stays on the Horizon tab (unlike runTestJobs,
     * which streams to the Traffic tab's console).
     */
    public function runHorizonTestJobs(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'horizon';
        RunWorkerPoolTestJobsJob::dispatch((string) $pool->id, 5, (string) (auth()->id() ?? '') ?: null);
        // Re-snapshot after the workers have had time to process the closures so
        // the recent jobs list reflects them (the test probe waits ~7s on-box).
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id)->delay(now()->addSeconds(14));
        $this->toastSuccess(__('Dispatching 5 test jobs — the dashboard refreshes in ~15s once the workers pick them up.'));
    }

    /**
     * Dispatch a handful of throwaway queued closures onto the app's queue and
     * verify the workers process them — streamed to the test console below.
     */
    public function runTestJobs(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $this->tab = 'traffic';
        RunWorkerPoolTestJobsJob::dispatch((string) $pool->id, 5, (string) (auth()->id() ?? '') ?: null);
        $this->toastSuccess(__('Dispatching 5 test jobs — watch the test console for whether the workers process them.'));
    }

    /**
     * Latest non-dismissed stats-probe console run, so Refresh stats shows the
     * raw per-member probe output (incl. Redis errors) for debugging.
     */
    public function statsRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'worker_pool_stats')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Latest non-dismissed test-jobs console run (server subject), for the
     * Traffic tab's test console banner.
     */
    /**
     * Real-time per-job event pushed from a worker box (via Reverb → Echo →
     * Livewire). Prepend to the live feed if it's for this pool; cap the window.
     *
     * @param  array<string, mixed>  $job
     */
    #[On('worker-pool-job')]
    public function onWorkerPoolJob(string $poolId, array $job): void
    {
        if ($poolId !== (string) ($this->server->worker_pool_id ?? '')) {
            return;
        }

        array_unshift($this->liveJobs, [
            'name' => (string) ($job['name'] ?? 'job'),
            'queue' => (string) ($job['queue'] ?? '?'),
            'status' => (string) ($job['status'] ?? 'processing'),
            // dply-clock timestamp (stamped at ingest) — rendered as "x ago"
            // against dply's now(), never the box's clock.
            'received_at' => (float) ($job['received_at'] ?? 0),
        ]);
        $this->liveJobs = array_slice($this->liveJobs, 0, 30);
    }

    public function testRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'worker_pool_test')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Re-kick the reconciler for a pool that stopped converging — e.g. a member
     * stuck in DEPLOYING after the ~20-min attempt budget ran out, or one whose
     * site provisioning has since finished. Sets status back to scaling so the
     * console run re-seeds and streams a fresh pass.
     */
    public function reconcileNow(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool to reconcile.'));

            return;
        }

        $pool->forceFill(['status' => WorkerPool::STATUS_SCALING])->save();
        ReconcileWorkerPoolJob::dispatch((string) $pool->id);
        $this->toastSuccess(__('Re-checking the pool — watch the console below for what each member is waiting on.'));
    }

    /**
     * Tear the whole pool down: drain + destroy all replicas and dissolve the
     * pool, leaving this server as a standalone worker. Destructive — the blade
     * gates it behind a typed confirmation.
     */
    public function tearDownPool(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool to tear down.'));

            return;
        }

        try {
            $count = $manager->dissolvePool($pool, auth()->user());
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->toastSuccess(__('Tearing down the pool — :n replica(s) draining and destroying. This server is now a standalone worker.', ['n' => $count]));
    }

    public function promote(string $serverId, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        $member = $pool?->servers->firstWhere('id', $serverId);
        if (! $pool || ! $member) {
            $this->toastError(__('Member not found.'));

            return;
        }

        try {
            $manager->promote($pool, $member);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->toastSuccess(__(':name is now the pool primary.', ['name' => $member->name]));
    }

    public function removeMember(string $serverId, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        $member = $pool?->servers->firstWhere('id', $serverId);
        if (! $pool || ! $member) {
            $this->toastError(__('Member not found.'));

            return;
        }

        try {
            $manager->removeMember($pool, $member);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        // Operator-initiated removal is a scale-DOWN: lower the target so the
        // reconciler doesn't immediately provision a replacement to "fill" the
        // now-missing slot. (The reconciler's own scale-down path leaves
        // desired_count alone — it's already the target there.) Then settle the
        // status so the pool doesn't sit stuck on "scaling".
        $newDesired = max(1, $pool->desired_count - 1);
        $pool->forceFill(['desired_count' => $newDesired, 'status' => WorkerPool::STATUS_SCALING])->save();
        $this->desired_count = $newDesired;
        ReconcileWorkerPoolJob::dispatch((string) $pool->id);

        $this->toastSuccess(__('Draining :name, then it will be destroyed. Desired count is now :n.', ['name' => $member->name, 'n' => $newDesired]));
    }

    public function applyExposure(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        ApplyWorkerPoolExposureJob::dispatch((string) $pool->id, (string) auth()->id());
        $this->toastSuccess(__('Applying backend exposure — binding backends and allowlisting worker IPs in the background.'));
    }

    public function addCrossRegion(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool to scale.'));

            return;
        }
        if (! $this->cr_ack_secrets) {
            $this->toastError(__('Confirm that secrets will be replicated to the new region/provider first.'));

            return;
        }

        try {
            $member = $manager->addCrossRegionReplica(
                $pool,
                trim($this->cr_region),
                $this->cr_size !== '' ? trim($this->cr_size) : null,
                $this->cr_credential_id !== '' ? $this->cr_credential_id : null,
                $this->cr_provider !== '' ? $this->cr_provider : null,
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->reset('cr_provider', 'cr_credential_id', 'cr_region', 'cr_size', 'cr_ack_secrets', 'cr_regions', 'cr_sizes');
        $this->toastSuccess(__('Provisioning :name in :region. Once ready it replays the sites and you’ll see which backends to expose.', [
            'name' => $member->name,
            'region' => $member->region,
        ]));
    }

    /**
     * Cross-region exposure requirements recorded on members (which private
     * backends must be exposed + the clone allowlisted). Flattened for the view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exposurePlan(): array
    {
        $pool = $this->pool();
        if (! $pool) {
            return [];
        }

        $plan = [];
        foreach ($pool->servers as $member) {
            $exposures = $member->meta['pool']['exposures'] ?? null;
            if (! is_array($exposures)) {
                continue;
            }
            foreach ($exposures as $e) {
                $plan[] = array_merge($e, ['member_name' => $member->name, 'member_ip' => $member->ip_address]);
            }
        }

        return $plan;
    }

    /**
     * Latest non-dismissed scaling console run for this server, fed to the
     * console-action-banner-static partial so the operator watches the
     * reconciler stream its work live. The partial's wire:poll re-renders this
     * component every few seconds while the run is in-flight.
     */
    public function scaleRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'worker_pool_scale')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function render(): View
    {
        $pool = $this->pool();
        $members = $pool
            ? $pool->servers()->orderByRaw("CASE WHEN pool_role = 'primary' THEN 0 ELSE 1 END")->orderBy('created_at')->get()
            : collect();

        // Self-heal a stale "scaling" status: the reconciler only flips the
        // column to steady when it fully converges, so a stopped/exhausted
        // reconcile can leave it stuck on "scaling" forever. If the pool is
        // actually settled (nothing converging or draining and active is at or
        // above desired), correct it here so the rest of the app agrees.
        if ($pool && $pool->status !== WorkerPool::STATUS_STEADY) {
            $converging = $members->filter(fn ($m) => ! $m->isPoolPrimary() && in_array($m->poolMemberState(), [WorkerPool::MEMBER_PROVISIONING, WorkerPool::MEMBER_REPLAYING, WorkerPool::MEMBER_DEPLOYING], true))->count();
            $draining = $members->filter(fn ($m) => $m->poolMemberState() === WorkerPool::MEMBER_DRAINING)->count();
            if ($converging === 0 && $draining === 0 && $pool->activeMemberCount() >= $pool->desired_count) {
                $pool->forceFill(['status' => WorkerPool::STATUS_STEADY])->save();
            }
        }

        // Cross-provider selectors: providers we can clone onto that the org has
        // a credential for, plus the source provider (same-provider region change).
        $supported = collect(WorkerCloneProvisioner::supportedProviders())
            ->map(fn ($p) => $p->value);
        $creds = ProviderCredential::query()
            ->where('organization_id', $this->server->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'provider']);
        $providerOptions = $creds->pluck('provider')->push($this->server->provider->value)
            ->unique()
            ->filter(fn ($p) => $supported->contains($p))
            ->values();
        $credentialOptions = $this->cr_provider !== ''
            ? $creds->where('provider', $this->cr_provider)->values()
            : collect();

        // Per-worker monthly estimate from the primary's billing tier (clones
        // default to the same size). Cents; 0 when specs aren't known yet.
        $perWorkerCents = ($pool?->primaryServer ?? $this->server)->billingTier()->priceCents();

        return view('livewire.servers.workspace-worker-pool', [
            'server' => $this->server,
            'pool' => $pool,
            'members' => $members,
            'providerOptions' => $providerOptions,
            'credentialOptions' => $credentialOptions,
            'perWorkerCents' => $perWorkerCents,
            'scaleRun' => $this->scaleRun(),
            'testRun' => $this->testRun(),
            'statsRun' => $this->statsRun(),
        ]);
    }
}
