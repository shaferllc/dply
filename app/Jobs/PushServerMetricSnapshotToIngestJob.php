<?php

namespace App\Jobs;

use App\Models\ServerMetricSnapshot;
use App\Services\Servers\ServerMetricsIngestClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushServerMetricSnapshotToIngestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public int $serverMetricSnapshotId,
    ) {}

    public function handle(ServerMetricsIngestClient $ingest): void
    {
        if (! (bool) config('server_metrics.ingest.enabled')) {
            return;
        }

        $snapshot = ServerMetricSnapshot::query()
            ->with('server')
            ->find($this->serverMetricSnapshotId);

        if ($snapshot === null) {
            return;
        }

        $ingest->send($snapshot);
    }
}
