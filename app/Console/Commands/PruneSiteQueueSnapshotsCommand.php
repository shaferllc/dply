<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteQueueSnapshot;
use Illuminate\Console\Command;

class PruneSiteQueueSnapshotsCommand extends Command
{
    protected $signature = 'dply:prune-site-queue-snapshots {--days= : Override the retention window}';

    protected $description = 'Delete site queue snapshots older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('dply.site_queue_snapshot_retention_days', 14));

        if ($days < 1) {
            $this->components->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        // Chunked: at one row per queue per five minutes, a long-neglected
        // install can hold millions, and a single unbounded DELETE would hold a
        // lock long enough to stall the sweep that is still writing.
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $batch = SiteQueueSnapshot::query()
                ->where('captured_at', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->components->info(sprintf('Pruned %d snapshot(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
