<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollEdgeStatusJob;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Cron-driven status sweep. Dispatches PollEdgeStatusJob for every
 * edge site whose status is "interesting" (provisioning or recently
 * active — to catch backend-side restarts / failures).
 *
 *   dply:edge:poll-status
 *
 * Schedule on a 60-second cadence; job is single-try so a slow
 * backend round trip doesn't pile up retries.
 */
class EdgePollStatusCommand extends Command
{
    protected $signature = 'dply:edge:poll-status
        {--include-active : Also poll sites already in STATUS_CONTAINER_ACTIVE (default: skip)}';

    protected $description = 'Sweep all edge sites and refresh their backend-reported status.';

    public function handle(): int
    {
        $includeActive = (bool) $this->option('include-active');

        $statuses = $includeActive
            ? [Site::STATUS_CONTAINER_PROVISIONING, Site::STATUS_CONTAINER_ACTIVE, Site::STATUS_CONTAINER_FAILED]
            : [Site::STATUS_CONTAINER_PROVISIONING, Site::STATUS_CONTAINER_FAILED];

        $count = 0;
        Site::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('container_backend_id')
            ->select('id')
            ->chunkById(100, function ($sites) use (&$count): void {
                foreach ($sites as $site) {
                    PollEdgeStatusJob::dispatch($site->id);
                    $count++;
                }
            });

        $this->info("Dispatched {$count} edge poll job(s).");

        return self::SUCCESS;
    }
}
