<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesPoolMemberEnv;
use App\Models\Server;
use App\Models\Site;
use App\Models\WorkerPool;
use App\Services\Sites\HorizonConfigDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Detect the Horizon worker config the pool's app actually needs and stash it on
 * the pool so the Horizon-config UI can offer it as a one-click suggestion.
 *
 * Detection is purely advisory — it NEVER pushes env or restarts workers. The
 * operator reviews the suggestion, applies it into the form, then explicitly
 * saves (which is the path that writes the box, via PushWorkerPoolHorizonConfigJob).
 *
 * Runs on dply-control (the worker-pool orchestration queue) because it SSHes to
 * a member box; SSH must never run inline in a request.
 */
class DetectWorkerPoolHorizonConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesPoolMemberEnv;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public string $poolId)
    {
        $this->onQueue('dply-control');
    }

    public function handle(HorizonConfigDetector $detector): void
    {
        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }

        // Detect against one box — the app is identical across the pool. Prefer
        // the primary; fall back to the first ready member.
        $member = $pool->servers->first(fn (Server $s): bool => $s->isReady() && $s->isPoolPrimary())
            ?? $pool->servers->first(fn (Server $s): bool => $s->isReady());

        if (! $member instanceof Server) {
            $this->store($pool, ['error' => 'No ready member to detect against.']);

            return;
        }

        $site = $this->appSite($member);
        if (! $site instanceof Site) {
            $this->store($pool, ['error' => 'No application site found on the worker member.']);

            return;
        }

        try {
            $result = $detector->detect($site);
        } catch (\Throwable $e) {
            Log::warning('DetectWorkerPoolHorizonConfigJob: detection failed', [
                'pool_id' => $pool->id,
                'error' => $e->getMessage(),
            ]);
            $this->store($pool, ['error' => $e->getMessage()]);

            return;
        }

        $this->store($pool, $result);
    }

    /**
     * @param  array<string, mixed>  $detection
     */
    private function store(WorkerPool $pool, array $detection): void
    {
        $meta = $pool->meta;
        $meta['horizon_detection'] = array_merge($detection, [
            'detected_at' => $detection['detected_at'] ?? now()->toIso8601String(),
        ]);
        $pool->forceFill(['meta' => $meta])->save();
    }
}
