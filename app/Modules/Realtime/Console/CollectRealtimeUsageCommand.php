<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Console;

use App\Models\RealtimeApp;
use App\Modules\Realtime\Services\RealtimeBackendFactory;
use Illuminate\Console\Command;

/**
 * Captures peak concurrent connections for each active realtime app from the
 * relay's /stats endpoint, recording the high-water mark on the app. v1 billing
 * is flat, so this is observational — it seeds the data for future
 * connection-based tiers. Runs hourly (see DplySchedule).
 */
class CollectRealtimeUsageCommand extends Command
{
    protected $signature = 'dply:realtime:collect-usage';

    protected $description = 'Record peak concurrent connections for active realtime apps.';

    public function handle(): int
    {
        $backend = RealtimeBackendFactory::make();
        $count = 0;

        RealtimeApp::query()
            ->where('status', RealtimeApp::STATUS_ACTIVE)
            ->cursor()
            ->each(function (RealtimeApp $app) use ($backend, &$count): void {
                $peak = $backend->fetchPeakConnections($app);
                if ($peak === null) {
                    return;
                }

                $app->forceFill([
                    // Keep the running high-water mark across collections within
                    // the window; the billing reset zeroes it per cycle.
                    'peak_connections' => max($peak, (int) $app->peak_connections),
                    'last_stats_at' => now(),
                ])->save();

                $count++;
            });

        $this->info("Recorded realtime usage for {$count} app(s).");

        return self::SUCCESS;
    }
}
