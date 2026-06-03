<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Models\WorkerPool;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\WorkerPools\WorkerPoolManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reads a pool's queue backlog (over SSH on the primary) and adjusts
 * desired_count within [min, max], respecting a cooldown. Writes the desired
 * count via {@see WorkerPoolManager::setDesiredCount()} so the normal reconciler
 * converges. Config lives in `pool.meta.autoscale`:
 *   { enabled, min, max, per_worker_backlog, cooldown_seconds, last_scaled_at, last_backlog }
 */
class AutoscaleWorkerPoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $poolId) {}

    public function handle(ExecuteRemoteTaskOnServer $executor, WorkerPoolManager $manager): void
    {
        $pool = WorkerPool::query()->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }

        $cfg = is_array($pool->meta['autoscale'] ?? null) ? $pool->meta['autoscale'] : [];
        if (! ($cfg['enabled'] ?? false)) {
            return;
        }

        $min = max(1, (int) ($cfg['min'] ?? 1));
        $max = max($min, (int) ($cfg['max'] ?? $pool->max_size));
        $perWorker = max(1, (int) ($cfg['per_worker_backlog'] ?? 100));
        $cooldown = max(60, (int) ($cfg['cooldown_seconds'] ?? 300));

        // Cooldown: don't flap.
        $lastAt = $cfg['last_scaled_at'] ?? null;
        if (is_string($lastAt) && $lastAt !== '') {
            $elapsed = Carbon::parse($lastAt)->diffInSeconds(now(), absolute: true);
            if ($elapsed < $cooldown) {
                return;
            }
        }

        $primary = $pool->primaryServer ?? $pool->sourceServer;
        if (! $primary instanceof Server || ! $primary->isReady()) {
            return;
        }

        $backlog = $this->readBacklog($executor, $primary);
        if ($backlog === null) {
            return; // couldn't read — leave as-is
        }

        $desired = max($min, min($max, (int) max(1, (int) ceil($backlog / $perWorker))));
        if ($backlog === 0) {
            $desired = $min;
        }

        $cfg['last_backlog'] = $backlog;
        if ($desired !== $pool->desired_count) {
            $cfg['last_scaled_at'] = now()->toIso8601String();
            $this->persistConfig($pool, $cfg);
            try {
                $manager->setDesiredCount($pool, $desired);
            } catch (\Throwable $e) {
                Log::warning('worker-pool: autoscale setDesiredCount failed', ['pool_id' => $pool->id, 'error' => $e->getMessage()]);
            }
        } else {
            $this->persistConfig($pool, $cfg);
        }
    }

    /** Read the default queue size on the primary's first site (over SSH). */
    private function readBacklog(ExecuteRemoteTaskOnServer $executor, Server $primary): ?int
    {
        $site = $primary->sites()->whereNotNull('repository_path')->orderBy('created_at')->first();
        $dir = $site instanceof Site ? ($site->repository_path ?: $site->document_root) : null;
        if (! is_string($dir) || $dir === '') {
            return null;
        }

        try {
            $out = $executor->runInlineBash(
                $primary,
                'worker-pool:queue-size',
                'cd '.escapeshellarg($dir).' && php artisan queue:size 2>/dev/null | tail -n1',
                timeoutSeconds: 30,
                asRoot: false,
            );
            if ($out->exitCode !== 0) {
                return null;
            }
            if (preg_match('/(\d+)/', (string) $out->buffer, $m)) {
                return (int) $m[1];
            }
        } catch (\Throwable $e) {
            Log::info('worker-pool: backlog read failed', ['server_id' => $primary->id, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /** @param array<string,mixed> $cfg */
    private function persistConfig(WorkerPool $pool, array $cfg): void
    {
        $meta = is_array($pool->meta) ? $pool->meta : [];
        $meta['autoscale'] = $cfg;
        $pool->forceFill(['meta' => $meta])->save();
    }
}
