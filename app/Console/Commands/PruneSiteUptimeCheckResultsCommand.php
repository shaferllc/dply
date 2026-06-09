<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SiteUptimeCheckResult;
use Illuminate\Console\Command;

/**
 * Keeps the append-only site_uptime_check_results table bounded. Only the
 * high-volume per-check rows are trimmed (default 90 days); incidents are kept
 * indefinitely. Scheduled daily — a brief overshoot between runs is harmless.
 */
class PruneSiteUptimeCheckResultsCommand extends Command
{
    protected $signature = 'uptime:prune-check-results';

    protected $description = 'Prune old site uptime check results so the history table stays bounded.';

    public function handle(): int
    {
        $days = max(7, (int) config('site_uptime.check_result_retention_days', 90));

        $deleted = SiteUptimeCheckResult::query()
            ->where('checked_at', '<', now()->subDays($days))
            ->delete();

        $this->info('Pruned '.$deleted.' uptime check result(s) older than '.$days.' day(s).');

        return self::SUCCESS;
    }
}
