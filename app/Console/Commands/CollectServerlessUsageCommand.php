<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Serverless\ServerlessUsageCollector;
use Illuminate\Console\Command;

/**
 * Collect daily serverless usage snapshots for dply-managed functions, used by
 * usage-based billing.
 *
 *   php artisan dply:serverless:collect-usage
 *   php artisan dply:serverless:collect-usage --date=2026-05-22
 *   php artisan dply:serverless:collect-usage --dry-run
 */
class CollectServerlessUsageCommand extends Command
{
    protected $signature = 'dply:serverless:collect-usage
                            {--date= : UTC date (Y-m-d) to collect; defaults to today (month-to-date roll-up)}
                            {--dry-run : Report counts without writing snapshots}';

    protected $description = 'Roll up managed-serverless invocations into usage snapshots for billing.';

    public function handle(ServerlessUsageCollector $collector): int
    {
        $dateInput = $this->option('date');
        $date = is_string($dateInput) && $dateInput !== ''
            ? now()->parse($dateInput)->startOfDay()
            : now()->startOfDay();

        $dryRun = (bool) $this->option('dry-run');

        $result = $collector->collectForDate($date, $dryRun);

        $this->info(sprintf(
            '%s managed serverless usage for %s — %d function(s), %d invocation(s)',
            $dryRun ? '[dry-run]' : 'Collected',
            $date->toDateString(),
            $result['sites'],
            $result['invocations'],
        ));

        return self::SUCCESS;
    }
}
