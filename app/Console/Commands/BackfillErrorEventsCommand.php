<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Errors\ErrorEventSyncer;
use Illuminate\Console\Command;

/**
 * One-shot seed of the error stream from existing failed operations, so the
 * Errors views aren't empty on launch. Reuses the same recorder as the live
 * sweep, so backfilled rows are identical to captured ones.
 */
class BackfillErrorEventsCommand extends Command
{
    protected $signature = 'dply:errors:backfill {--days=30 : How far back to seed, in days}';

    protected $description = 'Backfill the error stream from failed operations in the recent past.';

    public function handle(ErrorEventSyncer $syncer): int
    {
        $days = max(1, (int) $this->option('days'));
        $count = $syncer->sync(now()->subDays($days));

        $this->info("Backfilled {$count} error event(s) from the last {$days} day(s).");

        return self::SUCCESS;
    }
}
