<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerCronJobRun;
use Illuminate\Console\Command;

class PruneServerCronJobRunsCommand extends Command
{
    protected $signature = 'dply:prune-cron-job-runs';

    protected $description = 'Delete cron job run history older than the configured retention period.';

    public function handle(): int
    {
        $days = max(7, (int) config('cron_workspace.run_retention_days', 90));
        $cutoff = now()->subDays($days);

        $deleted = ServerCronJobRun::query()->where('started_at', '<', $cutoff)->delete();

        $this->info('Deleted '.$deleted.' row(s) older than '.$days.' days.');

        return self::SUCCESS;
    }
}
