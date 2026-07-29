<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Modules\Logs\Services\ServerLogUsageMeter;
use Illuminate\Console\Command;

/**
 * Roll dply Logs ingest volume out of ClickHouse into the per-org daily usage
 * table (PR A of docs/SERVER_LOGS_BILLING.md). Read-only metering — no billing,
 * no customer impact — so the GB/day numbers exist before pricing flips on.
 *
 *   php artisan dply:logs:meter                  # today (UTC)
 *   php artisan dply:logs:meter --yesterday      # nightly finalize of prior day
 *   php artisan dply:logs:meter --date=2026-06-15
 *   php artisan dply:logs:meter --date=2026-06-15 --days=7   # backfill a window
 *   php artisan dply:logs:meter --dry-run
 */
class MeterServerLogUsageCommand extends Command
{
    protected $signature = 'dply:logs:meter
                            {--date= : UTC date (Y-m-d) to meter; defaults to today}
                            {--yesterday : Meter yesterday (UTC) instead of today}
                            {--days=1 : Number of days, ending at the resolved date, to (re)meter}
                            {--dry-run : Report volume without writing usage rows}';

    protected $description = 'Meter per-org dply Logs ingest volume from ClickHouse into the daily usage table.';

    public function handle(ServerLogUsageMeter $meter): int
    {
        $dateInput = $this->option('date');
        $end = match (true) {
            (bool) $this->option('yesterday') => now()->subDay()->startOfDay(),
            is_string($dateInput) && $dateInput !== '' => now()->parse($dateInput)->startOfDay(),
            default => now()->startOfDay(),
        };

        $days = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');

        $totalBytes = 0;
        $reachable = true;

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $end->copy()->subDays($i);
            $result = $meter->meterDay($day, $dryRun);

            if (! $result['reachable']) {
                $reachable = false;

                break;
            }

            $totalBytes += $result['bytes'];

            $this->line(sprintf(
                '%s %s — %d org(s), %s, %d event(s)%s',
                $dryRun ? '[dry-run]' : 'Metered',
                $result['day'],
                $result['orgs'],
                $this->humanBytes($result['bytes']),
                $result['events'],
                $result['skipped'] > 0 ? sprintf(' (%d unknown-org group(s) skipped)', $result['skipped']) : '',
            ));
        }

        if (! $reachable) {
            $this->warn('ClickHouse log store not reachable — nothing to meter. '
                .'(Set CLICKHOUSE_HOST / CLICKHOUSE_DATABASE for this environment.)');

            return self::SUCCESS;
        }

        if ($days > 1) {
            $this->info(sprintf('Total over %d day(s): %s', $days, $this->humanBytes($totalBytes)));
        }

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d B', $bytes)
            : sprintf('%.2f %s', $value, $units[$unit]);
    }
}
