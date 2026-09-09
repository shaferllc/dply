<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AutoscaleWorkerPoolJob;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerPoolAutoTuner;
use Illuminate\Console\Command;

/**
 * Dispatches an autoscale evaluation per worker pool that has autoscaling
 * enabled. The job reads queue backlog and adjusts desired_count within bounds.
 */
class WorkerPoolAutoscaleCommand extends Command
{
    protected $signature = 'dply:worker-pools:autoscale';

    protected $description = 'Evaluate autoscaling and auto-optimisation for worker pools.';

    public function handle(WorkerPoolAutoTuner $tuner): int
    {
        $count = 0;
        $tuned = 0;

        WorkerPool::query()
            ->whereNotNull('meta')
            ->each(function (WorkerPool $pool) use (&$count, &$tuned, $tuner): void {
                if (($pool->meta['autoscale']['enabled'] ?? false) === true) {
                    AutoscaleWorkerPoolJob::dispatch((string) $pool->id);
                    $count++;
                }

                // Autoscale changes how many workers exist; auto-optimise sizes
                // each one to its box. Independent toggles — a fixed-size pool
                // still benefits from being tuned to its hardware.
                if (($pool->meta['auto_optimize']['enabled'] ?? false) === true) {
                    $result = $tuner->tune($pool);
                    if ($result['changed']) {
                        $tuned++;
                        $this->components->info(sprintf('Tuned %s: %s', $pool->name ?: $pool->id, $result['reason']));
                    }
                }
            });

        $this->components->info("Queued {$count} autoscale evaluation(s); tuned {$tuned} pool(s).");

        return self::SUCCESS;
    }
}
