<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Services\WorkerPools\WorkerWorkloadReplayer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 */
class ReconcileWorkerPoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Stop re-dispatching after this many ticks (~20 min at 30s) as a backstop. */
    private const MAX_ATTEMPTS = 40;

    public function __construct(
        public string $poolId,
        public int $attempt = 0,
    ) {}

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

        // 1) Scale up: provision replicas until active members reach desired.
        $active = $pool->activeMemberCount();
        $deficit = $pool->desired_count - $active;
        for ($i = 0; $i < $deficit; $i++) {
            try {
                $manager->addReplica($pool->fresh('servers'));
            } catch (\Throwable $e) {
                Log::warning('worker-pool: failed to provision replica', [
                    'pool_id' => $pool->id,
                    'error' => $e->getMessage(),
                ]);
                $pool->forceFill(['status' => WorkerPool::STATUS_DEGRADED])->save();
                break;
            }
        }

        // 2) Replay onto members that have provisioned and are now ready.
        $inFlight = false;
        foreach ($pool->fresh('servers')->servers as $member) {
            $state = $member->poolMemberState();
            if ($member->isPoolPrimary()) {
                continue;
            }
            if ($state === WorkerPool::MEMBER_DRAINING) {
                continue;
            }

            if ($state === WorkerPool::MEMBER_PROVISIONING) {
                if ($member->isReady()) {
                    $this->markState($member, WorkerPool::MEMBER_REPLAYING);
                    try {
                        $replayer->replicate($source, $member, asReplica: true);
                        $this->markState($member, WorkerPool::MEMBER_ACTIVE);
                    } catch (\Throwable $e) {
                        Log::warning('worker-pool: replay failed', [
                            'member_id' => $member->id,
                            'error' => $e->getMessage(),
                        ]);
                        // Leave in replaying state for a later retry tick.
                        $this->markState($member, WorkerPool::MEMBER_PROVISIONING);
                        $inFlight = true;
                    }
                } else {
                    $inFlight = true; // still provisioning at the provider
                }
            }
        }

        // 3) Settle status / re-dispatch while work remains.
        $pool->refresh();
        $stillScaling = $inFlight || $pool->activeMemberCount() < $pool->desired_count;

        if ($stillScaling && $this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->poolId, $this->attempt + 1)->delay(now()->addSeconds(30));

            return;
        }

        if (! $stillScaling) {
            $pool->forceFill(['status' => WorkerPool::STATUS_STEADY])->save();
        }
    }

    private function markState(Server $member, string $state): void
    {
        $meta = is_array($member->meta) ? $member->meta : [];
        $meta['pool'] = array_merge($meta['pool'] ?? [], ['state' => $state]);
        $member->forceFill(['meta' => $meta])->save();
    }
}
