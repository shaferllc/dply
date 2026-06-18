<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Console;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\ScheduledDeploy;
use App\Models\SiteDeployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Control-plane tick (every minute) that fires one-off DELAYED deploys whose
 * {@see ScheduledDeploy::$run_at} has arrived. The single-shot counterpart to
 * {@see RunDueDeploymentSchedulesCommand} (cron) — each row dispatches once and
 * is marked dispatched. Mirrors its VM-host guards.
 */
class RunDueScheduledDeploysCommand extends Command
{
    protected $signature = 'dply:run-due-scheduled-deploys';

    protected $description = 'Dispatch one-off delayed site deploys that are now due.';

    public function handle(): int
    {
        $now = now();

        $due = ScheduledDeploy::query()
            ->due($now)
            ->with('site.server')
            ->get();

        $dispatched = 0;

        foreach ($due as $scheduled) {
            $site = $scheduled->site;
            $server = $site?->server;

            // Consume the row regardless of deployability so a non-VM / deleted
            // site doesn't re-evaluate as due every minute forever.
            $scheduled->markDispatched($now);

            if ($site === null || $server === null) {
                continue;
            }

            // Only VM hosts run this clone/build/release pipeline; edge and
            // functions runtimes deploy differently and are skipped.
            if (! $server->isVmHost()
                || $site->usesFunctionsRuntime()
                || $site->usesEdgeRuntime()) {
                continue;
            }

            // Seed the deploy-active marker so any open UI immediately reflects
            // "Deploying…"; the job overwrites it with the real deployment id.
            Cache::put('site-deploy-active:'.$site->id, [
                'started_at' => $now->toIso8601String(),
                'deployment_id' => null,
            ], 600);

            RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_SCHEDULE);
            $dispatched++;

            Log::info('Delayed deploy dispatched', [
                'site_id' => $site->id,
                'scheduled_deploy_id' => $scheduled->id,
                'run_at' => $scheduled->run_at->toIso8601String(),
            ]);
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} delayed deploy(s).");
        }

        return self::SUCCESS;
    }
}
