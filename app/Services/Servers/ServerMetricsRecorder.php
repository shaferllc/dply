<?php

namespace App\Services\Servers;

use App\Jobs\PushServerMetricSnapshotToIngestJob;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use DateTimeInterface;

/**
 * Persists metric snapshots and optional central ingest (shared by SSH collect and guest HTTP push).
 */
class ServerMetricsRecorder
{
    public function storeSnapshot(Server $server, array $normalizedPayload, DateTimeInterface $capturedAt): ServerMetricSnapshot
    {
        $snapshot = ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => $capturedAt,
            'payload' => $normalizedPayload,
        ]);
        $this->pruneOldSnapshots($server->id);
        $this->touchMonitoringTrackingMeta($server->fresh(), $snapshot);

        if (config('server_metrics.ingest.enabled')) {
            $pending = PushServerMetricSnapshotToIngestJob::dispatch($snapshot->id);
            $queue = config('server_metrics.ingest.queue');
            if (is_string($queue) && $queue !== '') {
                $pending->onQueue($queue);
            }
        }

        return $snapshot;
    }

    protected function touchMonitoringTrackingMeta(Server $server, ServerMetricSnapshot $snapshot): void
    {
        $meta = $server->meta ?? [];
        $meta['monitoring_last_sample_at'] = $snapshot->captured_at->toIso8601String();

        $server->forceFill(['meta' => $meta])->saveQuietly();
    }

    protected function pruneOldSnapshots(string $serverId): void
    {
        ServerMetricSnapshot::query()
            ->where('server_id', $serverId)
            ->where('captured_at', '<', now()->subDays(30))
            ->delete();

        $keep = 800;
        $overflow = ServerMetricSnapshot::query()
            ->where('server_id', $serverId)
            ->orderByDesc('captured_at')
            ->offset($keep)
            ->limit(100_000)
            ->pluck('id');
        if ($overflow->isNotEmpty()) {
            ServerMetricSnapshot::query()->whereIn('id', $overflow)->delete();
        }
    }
}
