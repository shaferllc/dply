<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesPoolMemberEnv;
use App\Models\Server;
use App\Models\Site;
use App\Models\WorkerPool;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Support\WorkerPools\WorkerPoolHorizonConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * BOX-AUTHORITATIVE user-config push: writes the pool's Horizon knobs (HORIZON_*
 * + REDIS_QUEUE) into each member's .env and restarts the worker so Horizon
 * re-reads them. The box .env is the source of truth — dply is a convenience
 * writer, not an enforcer:
 *
 *  - $seedOnly = true  → only members that have NEVER had config applied (no
 *    `horizon_config_applied_at` marker) are written. Used on reconcile to seed
 *    NEW members without clobbering hand-edited existing ones.
 *  - $seedOnly = false → all members (explicit "Save & apply" — the user is
 *    choosing to re-sync everyone).
 *
 * dply's own agent plumbing (DPLY_POOL_EVENT_*) is pushed separately and
 * enforced by {@see PushWorkerPoolAgentConfigJob}.
 */
class PushWorkerPoolHorizonConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesPoolMemberEnv;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $poolId, public bool $seedOnly = false)
    {
        $this->onQueue('dply-control');
    }

    public function handle(
        SiteEnvPusher $pusher,
        DotEnvFileParser $parser,
        DotEnvFileWriter $writer,
        SiteSystemdProvisioner $provisioner,
    ): void {
        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }

        $envVars = WorkerPoolHorizonConfig::envVarsFor($pool);

        foreach ($pool->servers as $member) {
            if (! $member->isReady()) {
                continue;
            }
            // Box-authoritative: in seed mode, leave already-configured members
            // alone so hand edits survive reconciles.
            if ($this->seedOnly && ! empty($member->meta['horizon_config_applied_at'] ?? null)) {
                continue;
            }
            $site = $this->appSite($member);
            if (! $site instanceof Site) {
                continue;
            }

            $this->applyEnvToMember($site, $envVars, $parser, $writer, $pusher, $provisioner);
            $this->markApplied($member);
        }

        // Reflect the freshly applied config back into the dashboard.
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id)->delay(now()->addSeconds(6));
    }

    /**
     * Stamp the member so future seed-only reconciles skip it (box-authoritative).
     */
    private function markApplied(Server $member): void
    {
        $meta = is_array($member->meta) ? $member->meta : [];
        $meta['horizon_config_applied_at'] = now()->toIso8601String();
        $member->forceFill(['meta' => $meta])->save();
    }
}
