<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RedisSnapshot;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\RedisSnapshotExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue-side runner for a single redis RDB snapshot. Mirrors
 * {@see ExportServerDatabaseBackupJob} — catches all exceptions and marks the
 * row failed with the error message so the operator sees what went wrong in
 * the History tab.
 */
class ExportRedisSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public string $snapshotId,
    ) {
        $q = config('server_cache.snapshot_queue');
        if (is_string($q) && $q !== '') {
            $this->onQueue($q);
        }
    }

    public function handle(RedisSnapshotExporter $exporter, CacheServiceAuditLogger $auditLogger): void
    {
        $snapshot = RedisSnapshot::query()->with(['cacheService', 'server', 'backupConfiguration'])->find($this->snapshotId);
        if (! $snapshot) {
            return;
        }

        $server = $snapshot->server;
        if ($server === null) {
            return;
        }

        try {
            $exporter->export($snapshot);

            $user = $snapshot->user;
            if ($user) {
                $auditLogger->record(
                    $server,
                    ServerCacheServiceAuditEvent::EVENT_BGSAVE,
                    [
                        'snapshot_id' => $snapshot->id,
                        'engine' => $snapshot->cacheService?->engine,
                        'bytes' => $snapshot->fresh()?->bytes,
                        'storage_kind' => $snapshot->fresh()?->storage_kind,
                    ],
                    $user,
                );
            }

            $this->pruneOlderSnapshots($snapshot);
        } catch (\Throwable $e) {
            $snapshot->update([
                'status' => RedisSnapshot::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Keep the most-recent N completed snapshots per cache service. Failed and
     * pending rows are preserved so the History tab still surfaces error trails.
     */
    protected function pruneOlderSnapshots(RedisSnapshot $snapshot): void
    {
        $keep = max(1, (int) config('server_cache.snapshot_retention_per_service', 10));

        RedisSnapshot::query()
            ->where('server_cache_service_id', $snapshot->server_cache_service_id)
            ->where('status', RedisSnapshot::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->skip($keep)
            ->take(1000)
            ->get()
            ->each(fn (RedisSnapshot $old) => $old->delete());
    }
}
