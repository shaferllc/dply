<?php

namespace App\Services\WorkerPools;

use App\Jobs\ControlWorkerDaemonJob;
use App\Jobs\DrainAndDestroyWorkerJob;
use App\Jobs\PushWorkerPoolAgentConfigJob;
use App\Jobs\PushWorkerPoolHorizonConfigJob;
use App\Jobs\ReconcileWorkerPoolJob;
use App\Support\WorkerPools\WorkerPoolHorizonConfig;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
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
                // Seed explicit Horizon defaults so the config panel + env-var
                // push have concrete values from the moment the pool exists.
                'meta' => ['horizon_config' => WorkerPoolHorizonConfig::defaults(new WorkerPool)],
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

        $from = (int) $pool->desired_count;

        $pool->forceFill([
            'desired_count' => $desired,
            'status' => WorkerPool::STATUS_SCALING,
        ])->save();

        // Notify (in-app + email + webhooks) that a scale was requested. Only
        // when the target actually changed — re-applying the same count is a
        // no-op the operator shouldn't be paged about.
        if ($desired !== $from) {
            app(WorkerPoolNotifier::class)->scaleStarted($pool, $from, $desired);
        }

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

    /**
     * Tear the whole pool down: drain + destroy every replica, detach the
     * primary back into a standalone worker server, and delete the pool row.
     * The primary server itself is KEPT (this is its own workspace) — only the
     * pool construct and its replica boxes go away. Idempotent enough to re-run.
     *
     * servers.worker_pool_id has no DB foreign key (nullable, unconstrained), so
     * a replica still draining keeps a harmless dangling id until its job
     * destroys the row — safe to delete the pool immediately.
     *
     * @return int Number of replicas queued for drain + destroy.
     */
    public function dissolvePool(WorkerPool $pool, ?User $actor = null): int
    {
        $pool->load('servers');
        $primary = $pool->primaryServer ?? $pool->sourceServer;

        $drained = 0;
        foreach ($pool->servers as $member) {
            if ($member->isPoolPrimary()) {
                continue;
            }
            $meta = is_array($member->meta) ? $member->meta : [];
            $meta['pool'] = array_merge($meta['pool'] ?? [], ['state' => WorkerPool::MEMBER_DRAINING]);
            $member->forceFill(['meta' => $meta])->save();
            DrainAndDestroyWorkerJob::dispatch((string) $member->id, $actor?->id);
            $drained++;
        }

        if ($primary instanceof Server) {
            $meta = is_array($primary->meta) ? $primary->meta : [];
            unset($meta['pool']);
            $primary->forceFill([
                'worker_pool_id' => null,
                'pool_role' => null,
                'meta' => $meta,
            ])->save();
        }

        $pool->delete();

        return $drained;
    }

    /**
     * Ensure the queue daemon (Horizon when the app ships laravel/horizon, else
     * a plain `queue:work`) is defined and running on EVERY member of the pool.
     *
     * On VMs a worker runs as a {@see SiteProcess} that
     * {@see \App\Services\Sites\SiteSystemdProvisioner} materialises into a
     * systemd unit — NOT a SupervisorProgram. So we (1) make sure each member's
     * app site has an active worker SiteProcess with the right command, then
     * (2) dispatch {@see ProvisionSiteSystemdUnitsJob} for that site, which
     * writes the unit and `systemctl enable --now`s it (streamed to the site's
     * console banner). Idempotent: a member that already runs the daemon is
     * just re-provisioned.
     *
     * @return array{daemon: string, command: string, members: int}
     */
    public function ensureWorkersAcrossPool(WorkerPool $pool, ?User $actor = null): array
    {
        $pool->load('servers');
        $primaryApp = $this->appSiteForMember($pool->primaryServer ?? $pool->sourceServer);
        $useHorizon = $primaryApp !== null && ! empty(($primaryApp->resolvedRuntimeAppDetection() ?? [])['laravel_horizon']);

        $command = $useHorizon ? 'php artisan horizon' : 'php artisan queue:work';
        $name = $useHorizon ? 'horizon' : 'worker';
        $needle = $useHorizon ? 'horizon' : 'queue:work';

        $count = 0;
        foreach ($pool->servers as $member) {
            $site = $this->appSiteForMember($member);
            if (! $site instanceof Site) {
                continue;
            }

            $covered = $site->processes()
                ->where('is_active', true)
                ->get(['type', 'command'])
                ->contains(fn (SiteProcess $p): bool => str_contains(strtolower((string) $p->command), $needle));

            if (! $covered) {
                $site->processes()->create([
                    'type' => SiteProcess::TYPE_WORKER,
                    'name' => $name,
                    'command' => $command,
                    'scale' => 1,
                    'is_active' => true,
                ]);
            }

            // Write + enable --now the worker unit(s) on this member's box.
            ControlWorkerDaemonJob::dispatch((string) $site->id, 'ensure', $actor?->id !== null ? (string) $actor->id : null);
            $count++;
        }

        if ($count > 0) {
            // Enforced: dply's own real-time agent plumbing (DPLY_POOL_EVENT_*) is
            // always (re)asserted on every reconcile — idempotent, no restart when
            // unchanged. Not user config, so it never respects box edits.
            PushWorkerPoolAgentConfigJob::dispatch((string) $pool->id)->delay(now()->addSeconds(20));

            // Box-authoritative: seed the pool's Horizon knobs onto NEW members
            // only (those without an applied marker). Existing members' hand edits
            // survive reconciles; "Save & apply" is how the user re-syncs everyone.
            if ($useHorizon) {
                PushWorkerPoolHorizonConfigJob::dispatch((string) $pool->id, seedOnly: true)->delay(now()->addSeconds(22));
            }
        }

        return ['daemon' => $useHorizon ? 'horizon' : 'queue', 'command' => $command, 'members' => $count];
    }

    /**
     * Control the worker daemon (systemd) on a single member box.
     * $action ∈ ensure | start | stop | restart. Returns false when the member
     * has no resolvable app site to run workers from.
     */
    public function controlMemberWorkers(Server $member, string $action, ?User $actor = null, ?string $arg = null): bool
    {
        $site = $this->appSiteForMember($member);
        if (! $site instanceof Site) {
            return false;
        }

        ControlWorkerDaemonJob::dispatch((string) $site->id, $action, $actor?->id !== null ? (string) $actor->id : null, $arg);

        return true;
    }

    /**
     * Run a failed-job queue command (queue:retry / queue:forget / queue:flush)
     * against the pool's primary app over SSH — failed jobs live in the app's
     * shared backend, so the primary is the canonical place to manage them.
     */
    public function controlPrimaryQueue(WorkerPool $pool, string $action, ?string $arg = null, ?User $actor = null): bool
    {
        $primary = $pool->primaryServer ?? $pool->sourceServer;
        if (! $primary instanceof Server) {
            return false;
        }

        return $this->controlMemberWorkers($primary, $action, $actor, $arg);
    }

    /**
     * The Laravel application site hosted on a pool member (the box hosting the
     * replicated worker app). Prefers a framework-detected Laravel site, falling
     * back to the member's first site.
     */
    private function appSiteForMember(?Server $member): ?Site
    {
        if (! $member instanceof Server) {
            return null;
        }

        $sites = $member->sites()->get();

        return $sites->first(fn (Site $s): bool => $s->isLaravelFrameworkDetected()) ?? $sites->first();
    }
}
