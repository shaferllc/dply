<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Edge\EdgeAnalyticsEngineRollup;
use Illuminate\Console\Command;

class RollupEdgeAnalyticsEngineCommand extends Command
{
    protected $signature = 'dply:edge:rollup-analytics-engine
                            {--hours=2 : How many recent hours to pull from Analytics Engine SQL}';

    protected $description = 'Roll up Edge worker Analytics Engine metrics into edge_performance_hourly.';

    public function handle(EdgeAnalyticsEngineRollup $rollup): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $result = $rollup->rollupRecentHours($hours);

        $this->info(sprintf(
            'Analytics Engine rollup complete — scanned last %d hour(s), wrote %d hourly row(s).',
            $result['hours'],
            $result['rows'],
        ));

        return self::SUCCESS;
    }
}
