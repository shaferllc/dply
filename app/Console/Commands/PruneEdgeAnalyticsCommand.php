<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EdgeAccessLog;
use App\Models\EdgePerformanceHourly;
use App\Models\EdgeWebVital;
use Illuminate\Console\Command;

/**
 * Keeps Edge analytics tables bounded.
 */
class PruneEdgeAnalyticsCommand extends Command
{
    protected $signature = 'dply:edge:prune-analytics';

    protected $description = 'Prune old Edge access logs, web vitals, and hourly performance rows.';

    public function handle(): int
    {
        $deleted = 0;
        $deleted += $this->pruneTable(
            EdgeAccessLog::query(),
            (int) config('edge.analytics.access_logs_keep_per_site', 500),
            (int) config('edge.analytics.access_logs_days', 7),
        );
        $deleted += $this->pruneTable(
            EdgeWebVital::query(),
            (int) config('edge.analytics.web_vitals_keep_per_site', 200),
            (int) config('edge.analytics.web_vitals_days', 30),
        );
        $deleted += EdgePerformanceHourly::query()
            ->where('hour_start', '<', now()->subDays((int) config('edge.analytics.performance_hourly_days', 45)))
            ->delete();

        $this->info('Pruned '.$deleted.' Edge analytics row(s).');

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function pruneTable($query, int $keep, int $days): int
    {
        $deleted = (clone $query)
            ->where('occurred_at', '<', now()->subDays($days))
            ->delete();

        $siteIds = (clone $query)->distinct()->pluck('site_id');

        foreach ($siteIds as $siteId) {
            $keepIds = (clone $query)
                ->where('site_id', $siteId)
                ->orderByDesc('occurred_at')
                ->limit($keep)
                ->pluck('id');

            $deleted += (clone $query)
                ->where('site_id', $siteId)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }

        return $deleted;
    }
}
