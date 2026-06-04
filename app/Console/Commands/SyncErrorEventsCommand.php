<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Errors\ErrorEventSyncer;
use Illuminate\Console\Command;

/**
 * Sweep recently-failed ConsoleActions / SiteDeployments into the error stream.
 * Scheduled every minute with a generous overlap window so nothing is missed
 * between runs (capture is idempotent).
 */
class SyncErrorEventsCommand extends Command
{
    protected $signature = 'dply:errors:sync {--minutes=15 : Trailing window to scan, in minutes}';

    protected $description = 'Capture recently-failed operations into the dedicated error stream.';

    public function handle(ErrorEventSyncer $syncer): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $count = $syncer->sync(now()->subMinutes($minutes));

        $this->info("Captured {$count} new error event(s) from the last {$minutes} minute(s).");

        return self::SUCCESS;
    }
}
