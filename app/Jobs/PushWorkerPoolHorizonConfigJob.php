<?php

declare(strict_types=1);

namespace App\Jobs;

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
use Illuminate\Support\Facades\Log;

/**
 * Applies a worker pool's Horizon configuration (queues, process counts,
 * balance, memory, timeout, tries) to every member box by writing the
 * corresponding HORIZON_* env vars into each app's .env over SSH, then
 * restarting the worker units so Horizon re-reads them.
 *
 * "Env-var driven" by design: dply never edits the deployed app's
 * config/horizon.php — it just sets the vars that a dply-aware horizon.php
 * reads (see config/horizon.php). The pool meta is the source of truth; this
 * job is the projection of that truth onto the boxes.
 */
class PushWorkerPoolHorizonConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        $envVars = WorkerPoolHorizonConfig::envVarsFor($pool);

        foreach ($pool->servers as $member) {
            if (! $member instanceof Server || ! $member->isReady()) {
                continue;
            }
            $site = $this->appSite($member);
            if (! $site instanceof Site) {
                continue;
            }

            try {
                $this->upsertEnv($site, $envVars, $parser, $writer);
                $pusher->push($site);
                // Restart the worker units so `php artisan horizon` re-reads the
                // new .env (Restart=always also covers the brief exit window).
                $provisioner->controlWorkerUnits($site, 'restart');
            } catch (\Throwable $e) {
                Log::warning('PushWorkerPoolHorizonConfigJob: member failed', [
                    'pool_id' => $pool->id,
                    'server_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Reflect the freshly applied config back into the dashboard.
        CollectWorkerPoolHorizonSnapshotJob::dispatch((string) $pool->id)->delay(now()->addSeconds(6));
    }

    /**
     * Merge the HORIZON_* vars into the site's stored .env content (cache of
     * record) without disturbing the operator's other keys/comments.
     *
     * @param  array<string, string>  $envVars
     */
    private function upsertEnv(Site $site, array $envVars, DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $parsed = $parser->parse((string) ($site->env_file_content ?? ''));
        $variables = $parsed['variables'];
        foreach ($envVars as $key => $value) {
            $variables[$key] = (string) $value;
        }
        $site->forceFill(['env_file_content' => $writer->render($variables, $parsed['comments'])])->save();
    }

    private function appSite(Server $member): ?Site
    {
        $sites = $member->sites()->get();

        return $sites->first(fn (Site $s): bool => $s->isLaravelFrameworkDetected()) ?? $sites->first();
    }
}
