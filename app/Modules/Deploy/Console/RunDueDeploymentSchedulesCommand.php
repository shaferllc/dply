<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Console;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\SiteDeployment;
use App\Models\SiteDeploymentSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Control-plane tick (every minute) that dispatches any scheduled deploys whose
 * cron cadence has come due. Deploys are control-plane orchestrated, so this
 * runs on the dply scheduler — not a remote crontab. Mirrors the auto-pause
 * behaviour of the backup/redis snapshot schedule runners.
 */
class RunDueDeploymentSchedulesCommand extends Command
{
    protected $signature = 'dply:run-due-deployment-schedules';

    protected $description = 'Dispatch scheduled site deploys that are due to run.';

    public function handle(): int
    {
        $now = now();

        $schedules = SiteDeploymentSchedule::query()
            ->where('is_active', true)
            ->with('site.server')
            ->get();

        $dispatched = 0;

        foreach ($schedules as $schedule) {
            if (! $schedule->isDue($now)) {
                continue;
            }

            // Always advance last_run_at so a deployable-check failure doesn't
            // make us re-evaluate the same tick as "due" every minute.
            $schedule->recordRun($now);

            $site = $schedule->site;
            $server = $site?->server;

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

            Log::info('Scheduled deploy dispatched', [
                'site_id' => $site->id,
                'schedule_id' => $schedule->id,
                'cron' => $schedule->cron_expression,
            ]);
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} scheduled deploy(s).");
        }

        return self::SUCCESS;
    }
}
