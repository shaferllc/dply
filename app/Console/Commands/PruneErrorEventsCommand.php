<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use Illuminate\Console\Command;

/**
 * Cap the error stream's growth: drop dismissed events older than the dismissed
 * window, and any event older than the hard window. Scheduled daily.
 */
class PruneErrorEventsCommand extends Command
{
    protected $signature = 'dply:errors:prune
        {--dismissed-days=30 : Delete dismissed events older than this}
        {--max-days=90 : Delete any event older than this}';

    protected $description = 'Prune old / dismissed error events.';

    public function handle(): int
    {
        $dismissedDays = max(1, (int) $this->option('dismissed-days'));
        $maxDays = max($dismissedDays, (int) $this->option('max-days'));

        $dismissed = ErrorEvent::query()
            ->whereNotNull('dismissed_at')
            ->where('dismissed_at', '<', now()->subDays($dismissedDays))
            ->delete();

        $aged = ErrorEvent::query()
            ->where('occurred_at', '<', now()->subDays($maxDays))
            ->delete();

        $this->info("Pruned {$dismissed} dismissed + {$aged} aged error event(s).");

        return self::SUCCESS;
    }
}
