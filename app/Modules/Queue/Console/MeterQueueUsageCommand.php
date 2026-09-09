<?php

declare(strict_types=1);

namespace App\Modules\Queue\Console;

use App\Modules\Queue\Services\QueueUsageMeter;
use Illuminate\Console\Command;

/**
 * Flush the live dply Queue push counters into the per-org daily usage table
 * (docs/adr/dply-queue.md, decision 9).
 *
 * Read-only metering — no billing, no customer impact — so jobs/day exists
 * before pricing is calibrated against it, same staging as dply Logs.
 *
 *   php artisan dply:queue:meter            # flush every live counter
 *   php artisan dply:queue:meter --dry-run  # report totals without writing
 */
class MeterQueueUsageCommand extends Command
{
    protected $signature = 'dply:queue:meter
                            {--dry-run : Report the counters without writing usage rows}';

    protected $description = 'Flush dply Queue push counters into the per-org daily usage table.';

    public function handle(QueueUsageMeter $meter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $meter->flush($dryRun);

        if (! $result['reachable']) {
            $this->warn('Redis is not reachable — no queue push counters to flush. '
                .'(Metering accumulates in Redis; see QueueUsageMeter.)');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %s job(s) pushed across %d org(s) over %d org-day(s)%s',
            $dryRun ? '[dry-run]' : 'Metered',
            number_format($result['jobs']),
            $result['orgs'],
            $result['days'],
            $result['skipped'] > 0
                ? sprintf(' (%d expired or unknown-org counter(s) skipped)', $result['skipped'])
                : '',
        ));

        return self::SUCCESS;
    }
}
