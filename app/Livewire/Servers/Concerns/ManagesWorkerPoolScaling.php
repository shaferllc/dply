<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ReconcileWorkerPoolJob;
use App\Models\ConsoleAction;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerPoolManager;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesWorkerPoolScaling
{


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
}
