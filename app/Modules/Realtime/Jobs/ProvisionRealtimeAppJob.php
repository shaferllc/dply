<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Jobs;

use App\Models\RealtimeApp;
use App\Modules\Realtime\Services\RealtimeBackendFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Publishes a realtime app's credentials to the relay (Cloudflare KV write, or
 * the local fake store) and flips it to active. Idempotent — safe to retry.
 */
class ProvisionRealtimeAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $realtimeAppId) {}

    public function handle(): void
    {
        $app = RealtimeApp::query()->find($this->realtimeAppId);
        if ($app === null) {
            return;
        }

        try {
            // Reflect the target state in memory first so the credential record
            // published to the relay is marked enabled (kvRecord() derives
            // `enabled` from status). Persist only after a successful publish.
            $app->status = RealtimeApp::STATUS_ACTIVE;
            RealtimeBackendFactory::make()->provision($app);

            $app->forceFill([
                'status' => RealtimeApp::STATUS_ACTIVE,
                'error_message' => null,
            ])->save();
        } catch (Throwable $e) {
            $app->forceFill([
                'status' => RealtimeApp::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $app = RealtimeApp::query()->find($this->realtimeAppId);
        $app?->forceFill([
            'status' => RealtimeApp::STATUS_FAILED,
            'error_message' => $e->getMessage(),
        ])->save();
    }
}
