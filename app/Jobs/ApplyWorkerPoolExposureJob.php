<?php

namespace App\Jobs;

use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerPoolExposureApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Applies a pool's cross-region backend exposure: binds the backends + adds one
 * firewall allow rule per worker /32. Records the apply summary (and warnings)
 * on the pool meta so the UI can show what happened. See
 * {@see WorkerPoolExposureApplier}.
 */
class ApplyWorkerPoolExposureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $poolId,
        public ?string $actorId = null,
    ) {
        $this->onQueue('dply-control');
    }

    public function handle(WorkerPoolExposureApplier $applier): void
    {
        $pool = WorkerPool::query()->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }

        try {
            $result = $applier->applyForPool($pool, $this->actorId);
        } catch (\Throwable $e) {
            Log::warning('worker-pool: exposure apply failed', ['pool_id' => $pool->id, 'error' => $e->getMessage()]);
            $result = ['applied' => [], 'warnings' => [$e->getMessage()]];
        }

        $meta = is_array($pool->meta) ? $pool->meta : [];
        $meta['exposure'] = [
            'applied_at' => now()->toIso8601String(),
            'applied' => $result['applied'],
            'warnings' => $result['warnings'],
        ];
        $pool->forceFill(['meta' => $meta])->save();
    }
}
