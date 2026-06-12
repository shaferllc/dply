<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\WorkerPool;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\WorkerPools\WorkerMemberProviderProbe;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Services\WorkerPools\WorkerPoolNotifier;
use App\Services\WorkerPools\WorkerWorkloadReplayer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Converges a worker pool's actual member set to its desired_count.
 *
 * Re-entrant and idempotent: each run (1) provisions missing replicas,
 * (2) replays + deploys onto members that have just become ready, and
 * (3) re-dispatches itself while anything is still in flight. Scale-DOWN is
 * driven from {@see WorkerPoolManager::removeMember()} (operator-initiated) so
 * this job never destroys boxes on its own.
 *
 * Best-effort: a member that fails to provision is left for retry/inspection
 * and never causes healthy members to be torn down.
 *
 * Every tick streams its work into ONE {@see ConsoleAction} run
 * (kind `worker_pool_scale`) that spans the whole converge loop — the run id is
 * threaded through each self-redispatch — so the operator watches scaling live
 * on the pool primary's workspace. The run only goes terminal (completed /
 * failed) when the pool settles or the attempt budget is exhausted, and the
 * matching scaled / scale_failed notification fires there too.
 */
class ReconcileWorkerPoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    /** Stop re-dispatching after this many ticks (~20 min at 30s) as a backstop. */
    private const MAX_ATTEMPTS = 40;

    /**
     * A non-active member that hasn't advanced for this long is "wedged". We then
     * ask the provider whether its instance still exists; a confirmed-gone box
     * (destroyed out-of-band, IP recycled) is marked errored instead of looped on
     * forever. Generous so a slow-but-healthy provision is never killed.
     */
    private const STUCK_MINUTES = 15;

    private ?Server $resolvedSubject = null;

    public function __construct(
        public string $poolId,
        public int $attempt = 0,
        public ?string $runId = null,
    ) {
        // Dedicated control queue — only dply's Horizon drains it, so a managed
        // worker app sharing this Redis never grabs dply's own job classes.
        $this->onQueue('dply-control');
    }

    public function handle(WorkerPoolManager $manager, WorkerWorkloadReplayer $replayer): void
    {
        $pool = WorkerPool::query()->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }

        $pool->load('servers');
        $source = $pool->primaryServer ?? $pool->sourceServer;
        if (! $source instanceof Server) {
            return;
        }
        $this->resolvedSubject = $source;

        // Bind (or seed) the console run that spans every tick of this scale,
        // then stream this tick's work into it. (The working run id lives on the
        // WritesConsoleAction trait; $this->runId is the serialized carrier
        // threaded across re-dispatches — they must stay separate properties.)
        $this->bindConsoleRunId($this->runId);
        $emit = $this->beginConsoleAction();
        $this->runId = $this->currentConsoleRunId();

        try {
            $this->converge($pool, $manager, $replayer, $emit);
        } catch (\Throwable $e) {
            $emit->error('Reconcile tick crashed: '.$e->getMessage());
            $this->failConsoleAction($e->getMessage());
            $pool->forceFill(['status' => WorkerPool::STATUS_DEGRADED])->save();
            app(WorkerPoolNotifier::class)->scaleFailed($pool->fresh() ?? $pool, $e->getMessage());

            throw $e;
        }
    }

    private function converge(
        WorkerPool $pool,
        WorkerPoolManager $manager,
        WorkerWorkloadReplayer $replayer,
        ConsoleEmitter $emit,
    ): void {
        $source = $this->resolvedSubject;

        // 1) Converge member count to desired.
        $active = $pool->activeMemberCount();
        $deficit = $pool->desired_count - $active;
        $emit->step('reconcile', sprintf(
            'tick #%d — desired %d, active %d (%s)',
            $this->attempt + 1,
            $pool->desired_count,
            $active,
            $deficit > 0 ? 'scaling up '.$deficit : ($deficit < 0 ? 'scaling down '.(-$deficit) : 'balanced'),
        ));

        if ($deficit > 0) {
            // Scale up: provision replicas until active members reach desired.
            for ($i = 0; $i < $deficit; $i++) {
                try {
                    $replica = $manager->addReplica($pool->fresh('servers'));
                    $emit->success(sprintf('provisioning replica %s (%s)', $replica->name, $replica->region ?: 'same region'), 'provision');
                } catch (\Throwable $e) {
                    Log::warning('worker-pool: failed to provision replica', [
                        'pool_id' => $pool->id,
                        'error' => $e->getMessage(),
                    ]);
                    $emit->error('failed to provision a replica: '.$e->getMessage(), 'provision');
                    $pool->forceFill(['status' => WorkerPool::STATUS_DEGRADED])->save();
                    break;
                }
            }
        } elseif ($deficit < 0) {
            // Scale down: drain+destroy the newest non-primary members (LIFO),
            // never the primary. removeMember marks them draining + dispatches
            // DrainAndDestroyWorkerJob, so they drop out of activeMemberCount.
            $surplus = -$deficit;
            $victims = $pool->servers
                ->filter(fn (Server $s): bool => ! $s->isPoolPrimary() && $s->poolMemberState() !== WorkerPool::MEMBER_DRAINING)
                ->sortByDesc(fn (Server $s) => $s->created_at?->getTimestamp() ?? 0)
                ->take($surplus);
            foreach ($victims as $victim) {
                try {
                    $manager->removeMember($pool, $victim);
                    $emit->info(sprintf('draining %s, then destroying it', $victim->name), 'drain');
                } catch (\Throwable $e) {
                    Log::warning('worker-pool: failed to drain replica', [
                        'pool_id' => $pool->id,
                        'member_id' => $victim->id,
                        'error' => $e->getMessage(),
                    ]);
                    $emit->warn(sprintf('could not drain %s: %s', $victim->name, $e->getMessage()), 'drain');
                }
            }
        }

        // 2) Replay onto members that have provisioned and are now ready.
        $inFlight = false;
        $activated = false;
        foreach ($pool->fresh('servers')->servers as $member) {
            $state = $member->poolMemberState();
            if ($member->isPoolPrimary()) {
                continue;
            }
            if ($state === WorkerPool::MEMBER_DRAINING) {
                continue;
            }

            if ($state === WorkerPool::MEMBER_ERRORED) {
                continue; // wedged/gone — left for operator inspection or removal
            }

            if ($state === WorkerPool::MEMBER_PROVISIONING) {
                // Gate on full provisioning (status ready AND setup done), NOT just
                // isReady(): `status` flips to ready the moment the IP is known,
                // long before the OS setup script finishes. Replaying onto a
                // half-built box was how a member advanced to deploying while its
                // box was still being created (and sometimes vanished mid-setup).
                if ($member->isProvisioningComplete()) {
                    $this->markState($member, WorkerPool::MEMBER_REPLAYING);
                    $emit->step('replay', sprintf('%s is ready — replaying workload', $member->name));
                    try {
                        $replayer->replicate($source, $member, asReplica: true);
                        // Replay recorded pending_deploys on the member; move to
                        // DEPLOYING so the deploy step below picks it up once each
                        // site finishes provisioning.
                        $this->markState($member, WorkerPool::MEMBER_DEPLOYING);
                        $emit->info(sprintf('%s replay done — deploying sites', $member->name), 'replay');
                        $inFlight = true;
                    } catch (\Throwable $e) {
                        Log::warning('worker-pool: replay failed', [
                            'member_id' => $member->id,
                            'error' => $e->getMessage(),
                        ]);
                        $emit->warn(sprintf('%s replay failed (will retry): %s', $member->name, $e->getMessage()), 'replay');
                        // Leave in provisioning state for a later retry tick.
                        $this->markState($member, WorkerPool::MEMBER_PROVISIONING);
                        $inFlight = true;
                    }
                } elseif ($this->guardWedgedMember($member, $pool, $emit)) {
                    continue; // confirmed gone — marked errored, skip
                } else {
                    $emit->info(sprintf('%s still provisioning at the provider…', $member->name), 'provision');
                    $inFlight = true; // still provisioning at the provider
                }
            } elseif ($state === WorkerPool::MEMBER_DEPLOYING) {
                if ($this->guardWedgedMember($member, $pool, $emit)) {
                    continue; // box vanished mid-deploy — marked errored, skip
                }
                if ($this->dispatchReadyDeploys($member, $emit)) {
                    $this->markState($member, WorkerPool::MEMBER_ACTIVE);
                    $activated = true;
                    $emit->success(sprintf('%s is active', $member->name), 'deploy');
                    // A cross-region member needs its backends opened to its IP.
                    // Auto-apply (the operator already confirmed by adding it).
                    if ((bool) ($member->meta['cross_region'] ?? false)) {
                        ApplyWorkerPoolExposureJob::dispatch((string) $pool->id);
                    }
                } else {
                    $inFlight = true; // some sites still provisioning
                }
            }
        }

        // 2b) A member that just went ACTIVE has its sites deployed but no queue
        // daemon yet — the box is "active" but processes 0 jobs. Materialise the
        // worker SiteProcess + write/enable the systemd (or supervisor) unit +
        // seed the pool's Horizon config onto the new member(s). Idempotent across
        // the whole pool, so we run it once per tick when anything activated.
        if ($activated) {
            $emit->step('workers', 'new member active — ensuring queue daemons + Horizon config across the pool');
            try {
                $result = $manager->ensureWorkersAcrossPool($pool->fresh('servers'));
                $emit->success(sprintf(
                    'ensured %s daemon on %d member(s)',
                    $result['daemon'],
                    $result['members'],
                ), 'workers');
            } catch (\Throwable $e) {
                Log::warning('worker-pool: ensureWorkers failed after activation', [
                    'pool_id' => $pool->id,
                    'error' => $e->getMessage(),
                ]);
                $emit->warn('could not ensure worker daemons (will retry next tick): '.$e->getMessage(), 'workers');
                $inFlight = true; // keep ticking so the next pass retries the daemon setup
            }
        }

        // 3) Settle status / re-dispatch while work remains.
        $pool->refresh();
        $stillScaling = $inFlight || $pool->activeMemberCount() < $pool->desired_count;

        if (! $stillScaling) {
            $pool->forceFill(['status' => WorkerPool::STATUS_STEADY])->save();
            $emit->success(sprintf('pool settled — %d active worker(s)', $pool->activeMemberCount()), 'reconcile');
            $this->completeConsoleAction();
            app(WorkerPoolNotifier::class)->scaled($pool, $pool->activeMemberCount());

            return;
        }

        if ($this->attempt < self::MAX_ATTEMPTS) {
            $emit->info('still converging — re-checking in 30s', 'reconcile');
            self::dispatch($this->poolId, $this->attempt + 1, $this->currentConsoleRunId())
                ->delay(now()->addSeconds(30));

            return;
        }

        // Attempt budget exhausted while still scaling — give up and surface it.
        $pool->forceFill(['status' => WorkerPool::STATUS_DEGRADED])->save();
        $emit->error('scaling did not converge after the maximum number of attempts', 'reconcile');
        $this->failConsoleAction('Scaling did not converge before the attempt limit (~20 min).');
        app(WorkerPoolNotifier::class)->scaleFailed($pool, 'Scaling did not converge before the attempt limit.');
    }

    /**
     * Deploy each of the member's replicated sites once it has finished
     * provisioning (meta.provisioning.state === 'ready'). Returns true when no
     * sites remain pending (all deploys dispatched), false while some are still
     * provisioning. Idempotent: dispatched sites are removed from the list.
     */
    private function dispatchReadyDeploys(Server $member, ConsoleEmitter $emit): bool
    {
        $meta = is_array($member->meta) ? $member->meta : [];
        $pending = $meta['pool']['pending_deploys'] ?? [];
        if (! is_array($pending) || $pending === []) {
            return true;
        }

        $remaining = [];
        foreach ($pending as $siteId) {
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                continue; // site gone — drop it
            }
            $provState = $site->meta['provisioning']['state'] ?? null;
            if ($provState === 'ready') {
                RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_MANUAL);
                $emit->info(sprintf('%s: dispatching deploy for %s', $member->name, $site->name), 'deploy');
            } else {
                $remaining[] = $siteId;
                // Surface WHY the member is stuck in DEPLOYING: it's waiting on
                // this site to finish provisioning before its deploy can run.
                $emit->warn(sprintf(
                    '%s: waiting on site %s to finish provisioning (state: %s) before deploying',
                    $member->name,
                    $site->name,
                    $provState ?? 'unknown',
                ), 'deploy');
            }
        }

        $meta['pool']['pending_deploys'] = $remaining;
        $member->forceFill(['meta' => $meta])->save();

        return $remaining === [];
    }

    private function markState(Server $member, string $state): void
    {
        $meta = is_array($member->meta) ? $member->meta : [];
        $prev = $meta['pool']['state'] ?? null;
        $meta['pool'] = array_merge($meta['pool'] ?? [], ['state' => $state]);
        // Stamp when we ENTERED this state so guardWedgedMember can measure how
        // long a member has been stuck without advancing.
        if ($prev !== $state) {
            $meta['pool']['state_since'] = now()->toIso8601String();
        }
        $member->forceFill(['meta' => $meta])->save();
    }

    /**
     * A member that has been provisioning/deploying past {@see STUCK_MINUTES}
     * without advancing is "wedged". Ask the provider whether its instance still
     * exists; on a CONFIRMED "not found" (destroyed out-of-band, IP recycled) mark
     * it errored and degrade the pool so it stops being a silent zombie. Anything
     * ambiguous (transient API error, unsupported provider) is left alone.
     *
     * @return bool true when the member was marked errored (caller should skip it)
     */
    private function guardWedgedMember(Server $member, WorkerPool $pool, ConsoleEmitter $emit): bool
    {
        $sinceRaw = $member->meta['pool']['state_since'] ?? $member->created_at?->toIso8601String();
        $since = is_string($sinceRaw) ? CarbonImmutable::parse($sinceRaw) : null;
        if ($since === null || $since->gt(now()->subMinutes(self::STUCK_MINUTES))) {
            return false; // not stuck long enough yet
        }

        $exists = app(WorkerMemberProviderProbe::class)->instanceExists($member);
        if ($exists !== false) {
            // true (healthy, just slow) or null (couldn't tell) — never tear down.
            if ($exists === null) {
                $emit->warn(sprintf(
                    '%s has been wedged for >%dm; could not confirm with the provider whether it still exists.',
                    $member->name,
                    self::STUCK_MINUTES,
                ), 'provision');
            }

            return false;
        }

        Log::warning('worker-pool: member instance not found at provider — marking errored', [
            'pool_id' => $pool->id,
            'member_id' => $member->id,
            'provider' => $member->provider->value,
            'provider_id' => $member->provider_id,
        ]);
        $this->markState($member, WorkerPool::MEMBER_ERRORED);
        $emit->error(sprintf(
            '%s no longer exists at %s (instance %s) — its box was destroyed out-of-band. Marked errored; remove it to let the pool re-scale.',
            $member->name,
            $member->provider->value,
            $member->provider_id ?: 'unknown',
        ), 'provision');
        $pool->forceFill(['status' => WorkerPool::STATUS_DEGRADED])->save();
        app(WorkerPoolNotifier::class)->scaleFailed(
            $pool->fresh() ?? $pool,
            sprintf('Worker %s vanished at the provider and was marked errored.', $member->name),
        );

        return true;
    }

    protected function consoleSubject(): Model
    {
        if ($this->resolvedSubject instanceof Server) {
            return $this->resolvedSubject;
        }

        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        $server = $pool?->primaryServer ?? $pool?->sourceServer;

        return $this->resolvedSubject = ($server instanceof Server ? $server : new Server);
    }

    protected function consoleKind(): string
    {
        return 'worker_pool_scale';
    }
}
