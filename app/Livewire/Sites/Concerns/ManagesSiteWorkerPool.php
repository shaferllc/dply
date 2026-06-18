<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Jobs\CollectWorkerPoolStatsJob;
use App\Models\Concerns\Site\HasSiteRelationships;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerPoolManager;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteWorkerPool
{
    /** Optional custom HTML for the worker page; empty = built-in dply page. */
    public string $worker_page_html = '';

    /**
     * Resolve a worker pool that's actually attached to this site (workspace-
     * scoped), so the site Workers panel can only act on its own fleet.
     */
    private function resolveAttachedPool(string $poolId): ?WorkerPool
    {
        return $this->site->attachedWorkerPools()->firstWhere('id', $poolId);
    }

    /**
     * Explicitly attach an existing org worker pool to this site. Once any pool
     * is explicitly attached, the explicit set fully defines the site's workers
     * (see {@see HasSiteRelationships::attachedWorkerPools()}).
     */
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
}
