<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Edge\EdgeUsageCollector;
use Illuminate\Console\Command;

/**
 * Collect daily Edge delivery usage snapshots for billing pass-through.
 *
 *   php artisan dply:edge:collect-usage
 *   php artisan dply:edge:collect-usage --date=2026-05-22
 *   php artisan dply:edge:collect-usage --dry-run
 */
class CollectEdgeUsageCommand extends Command
{
    protected $signature = 'dply:edge:collect-usage
                            {--date= : UTC date (Y-m-d) to collect; defaults to yesterday}
                            {--dry-run : Report counts without writing snapshots}';

    protected $description = 'Collect Edge delivery usage snapshots for usage-based billing.';

    public function handle(EdgeUsageCollector $collector): int
    {
        $dateInput = $this->option('date');
        $date = is_string($dateInput) && $dateInput !== ''
            ? now()->parse($dateInput)->startOfDay()
            : now()->subDay()->startOfDay();

        $dryRun = (bool) $this->option('dry-run');

        $result = $collector->collectForDate($date, $dryRun);

        $this->info(sprintf(
            '%s Edge usage for %s — %d site(s), source=%s',
            $dryRun ? '[dry-run]' : 'Collected',
            $date->toDateString(),
            $result['sites'],
            $result['source'],
        ));

        if ($result['source'] === 'placeholder' && $result['sites'] > 0) {
            $this->warn('Cloudflare analytics not configured — wrote placeholder zero snapshots.');
            $this->line('  TODO: set DPLY_EDGE_CF_ACCOUNT_ID, DPLY_EDGE_CF_API_TOKEN, DPLY_EDGE_CF_ZONE_NAME');
            $this->line('  Or import manual usage via EdgeUsageSnapshot::SOURCE_MANUAL rows.');
        }

        return self::SUCCESS;
    }
}
