<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AutoscaleWorkerPoolJob;
use App\Models\WorkerPool;
use Illuminate\Console\Command;

/**
 * Dispatches an autoscale evaluation per worker pool that has autoscaling
 * enabled. The job reads queue backlog and adjusts desired_count within bounds.
 */
class WorkerPoolAutoscaleCommand extends Command
{
    protected $signature = 'dply:worker-pools:autoscale';

    protected $description = 'Evaluate autoscaling for worker pools with autoscale enabled.';

    public function handle(): int
    {
        $count = 0;
        WorkerPool::query()
            ->whereNotNull('meta')
            ->each(function (WorkerPool $pool) use (&$count): void {
                if (($pool->meta['autoscale']['enabled'] ?? false) === true) {
                    AutoscaleWorkerPoolJob::dispatch((string) $pool->id);
                    $count++;
                }
            });

        $this->components->info("Queued {$count} worker-pool autoscale evaluation(s).");

        return self::SUCCESS;
    }
}
