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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ENFORCED plumbing push: writes dply's own agent wiring (DPLY_POOL_EVENT_URL +
 * _TOKEN, the real-time forwarder) to every member's .env. dply always owns
 * these — they're not user config — so this runs on every reconcile/ensure and
 * is idempotent (no-op + no restart once the box already matches).
 *
 * The user's Horizon knobs are pushed separately and box-authoritatively by
 * {@see PushWorkerPoolHorizonConfigJob}.
 */
class PushWorkerPoolAgentConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesPoolMemberEnv;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $poolId)
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

        $envVars = $this->agentEnvVars($pool);

        foreach ($pool->servers as $member) {
            if (! $member instanceof Server || ! $member->isReady()) {
                continue;
            }
            $site = $this->appSite($member);
            if ($site instanceof Site) {
                $this->applyEnvToMember($site, $envVars, $parser, $writer, $pusher, $provisioner);
            }
        }
    }

    /**
     * Mint the pool's event token once (persisted on meta) and build the agent
     * env. Base URL defaults to app.url, overridable via DPLY_POOL_EVENT_INGEST_BASE
     * for when boxes must reach dply on a different public host (e.g. a dev tunnel).
     *
     * @return array<string, string>
     */
    private function agentEnvVars(WorkerPool $pool): array
    {
        $meta = is_array($pool->meta) ? $pool->meta : [];
        $token = (string) ($meta['event_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            $meta['event_token'] = $token;
            $pool->forceFill(['meta' => $meta])->save();
        }

        $base = rtrim((string) (env('DPLY_POOL_EVENT_INGEST_BASE') ?: config('app.url')), '/');

        return [
            'DPLY_POOL_EVENT_URL' => $base.'/api/worker-pools/'.$pool->id.'/job-events',
            'DPLY_POOL_EVENT_TOKEN' => $token,
        ];
    }
}
