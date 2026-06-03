<?php

namespace App\Services\WorkerPools;

use App\Jobs\DrainAndDestroyWorkerJob;
use App\Jobs\ReconcileWorkerPoolJob;
use App\Models\Server;
use App\Models\User;
use App\Models\WorkerPool;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and mutates worker pools and enforces the single-primary invariant.
 * Provisioning / replay / drain happen asynchronously via the reconciler and
 * the drain job — this class only mutates DB state and dispatches those jobs.
 */
class WorkerPoolManager
{
    public function __construct(
        private readonly WorkerCloneProvisioner $cloneProvisioner,
    ) {}

    /**
     * Turn an existing, ready worker server into the primary of a new pool.
     */
    public function createPool(User $actor, Server $source, string $name): WorkerPool
    {
        if (! $source->isWorkerHost()) {
            throw new RuntimeException(__('Only worker servers can start a worker pool.'));
        }
        if ($source->worker_pool_id !== null) {
            throw new RuntimeException(__('This server is already part of a worker pool.'));
        }
        if (! $source->isReady()) {
            throw new RuntimeException(__('The server must be ready before creating a pool.'));
        }

        return DB::transaction(function () use ($source, $name): WorkerPool {
            $pool = WorkerPool::query()->create([
                'organization_id' => $source->organization_id,
                'name' => $name !== '' ? $name : ($source->name.' pool'),
                'source_server_id' => $source->id,
                'primary_server_id' => $source->id,
                'desired_count' => 1,
                'max_size' => 10,
                'status' => WorkerPool::STATUS_STEADY,
            ]);

            $source->forceFill([
                'worker_pool_id' => $pool->id,
                'pool_role' => WorkerPool::ROLE_PRIMARY,
            ])->save();

            return $pool;
        });
    }

    /**
     * Set the target member count (including the primary) and kick the reconciler.
     * Rejects values above max_size or below 1.
     */
    public function setDesiredCount(WorkerPool $pool, int $desired): void
    {
        $desired = max(1, $desired);
        if ($desired > $pool->max_size) {
            throw new RuntimeException(__('Desired count exceeds the pool max size (:max).', ['max' => $pool->max_size]));
        }

        $pool->forceFill([
            'desired_count' => $desired,
            'status' => WorkerPool::STATUS_SCALING,
        ])->save();

        ReconcileWorkerPoolJob::dispatch((string) $pool->id);
    }

    /**
     * Promote a replica to primary, demoting the current primary. Flips roles
     * atomically; the scheduler follows on the next deploy/cron sync.
     */
    public function promote(WorkerPool $pool, Server $member): void
    {
        if ($member->worker_pool_id !== $pool->id) {
            throw new RuntimeException(__('That server is not a member of this pool.'));
        }
        if ($member->isPoolPrimary()) {
            return;
        }

        DB::transaction(function () use ($pool, $member): void {
            // Clear all roles first so the partial unique index never trips
            // mid-swap, then assign the new primary.
            Server::query()->where('worker_pool_id', $pool->id)
                ->update(['pool_role' => WorkerPool::ROLE_REPLICA]);
            $member->forceFill(['pool_role' => WorkerPool::ROLE_PRIMARY])->save();
            $pool->forceFill(['primary_server_id' => $member->id])->save();
        });
    }

    /**
     * Remove a member: drains its workers then tears the box down. The primary
     * cannot be removed until another member is promoted.
     */
    public function removeMember(WorkerPool $pool, Server $member): void
    {
        if ($member->worker_pool_id !== $pool->id) {
            throw new RuntimeException(__('That server is not a member of this pool.'));
        }
        if ($member->isPoolPrimary()) {
            throw new RuntimeException(__('Promote another member before removing the primary.'));
        }

        $meta = is_array($member->meta) ? $member->meta : [];
        $meta['pool'] = array_merge($meta['pool'] ?? [], ['state' => WorkerPool::MEMBER_DRAINING]);
        $member->forceFill(['meta' => $meta])->save();

        DrainAndDestroyWorkerJob::dispatch((string) $member->id);
    }

    /**
     * Provision one new replica clone of the pool's source worker. Returns the
     * new Server row (status pending; provisioning dispatched).
     */
    public function addReplica(WorkerPool $pool): Server
    {
        $source = $pool->primaryServer ?? $pool->sourceServer;
        if (! $source instanceof Server) {
            throw new RuntimeException(__('Pool has no source server to clone.'));
        }

        return $this->cloneProvisioner->provisionReplica($pool, $source);
    }

    /**
     * Add one replica in a DIFFERENT region (and/or with a different provider
     * credential or size). Cross-region members can't use the private network,
     * so the replayer rewrites their env to the backends' public addresses and
     * records an exposure plan on the member. Bumps desired_count by one so the
     * same-region reconcile loop doesn't also add a local replica to "fill".
     */
    public function addCrossRegionReplica(WorkerPool $pool, string $region, ?string $size = null, ?string $credentialId = null, ?string $provider = null): Server
    {
        $source = $pool->primaryServer ?? $pool->sourceServer;
        if (! $source instanceof Server) {
            throw new RuntimeException(__('Pool has no source server to clone.'));
        }
        $region = trim($region);
        $provider = trim((string) $provider);
        $sameProvider = $provider === '' || $provider === $source->provider->value;
        // A cross-region worker must differ in region OR provider from the source.
        if ($sameProvider && ($region === '' || $region === (string) $source->region)) {
            throw new RuntimeException(__('Choose a different region (or provider) from the source for a cross-region worker.'));
        }
        if ($pool->desired_count + 1 > $pool->max_size) {
            throw new RuntimeException(__('Adding a worker would exceed the pool max size (:max).', ['max' => $pool->max_size]));
        }

        $member = $this->cloneProvisioner->provisionReplica($pool, $source, array_filter([
            'region' => $region,
            'size' => (string) $size,
            'provider' => $provider,
            'provider_credential_id' => (string) $credentialId,
        ], fn ($v) => $v !== null && $v !== ''));

        $pool->forceFill([
            'desired_count' => $pool->desired_count + 1,
            'status' => WorkerPool::STATUS_SCALING,
        ])->save();

        ReconcileWorkerPoolJob::dispatch((string) $pool->id);

        return $member;
    }
}
