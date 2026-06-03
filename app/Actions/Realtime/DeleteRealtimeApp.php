<?php

declare(strict_types=1);

namespace App\Actions\Realtime;

use App\Models\RealtimeApp;
use App\Services\Realtime\RealtimeBackendFactory;
use Illuminate\Support\Facades\Log;

/**
 * Deprovisions a realtime app (revokes connect + publish at the relay) and
 * deletes the row. Best-effort on the relay teardown — a KV delete failure
 * shouldn't strand the row, but it is logged.
 */
class DeleteRealtimeApp
{
    public function handle(RealtimeApp $app): void
    {
        try {
            RealtimeBackendFactory::make()->deprovision($app);
        } catch (\Throwable $e) {
            Log::warning('realtime_deprovision_failed', [
                'realtime_app_id' => $app->id,
                'error' => $e->getMessage(),
            ]);
        }

        $app->delete();
    }
}
