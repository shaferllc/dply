<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\AsJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Action Queue/Job Dashboard - Monitor queued actions.
 *
 * Provides dashboard and management capabilities for queued actions.
 *
 * @example
 * // Get queue status for an action
 * $status = ActionQueue::getStatus(ProcessOrder::class);
 * // Returns: [
 * //     'action' => 'App\Actions\ProcessOrder',
 * //     'queued' => 45,
 * //     'processed' => 120,
 * //     'failed' => 5,
 * //     'pending' => 45,
 * // ]
 * @example
 * // Get dashboard for all queued actions
 * $dashboard = ActionQueue::dashboard();
 * // Returns: [
 * //     'total_queued' => 150,
 * //     'total_processed' => 500,
 * //     'total_failed' => 10,
 * //     'actions' => [...],
 * // ]
 * @example
 * // Get failed jobs
 * $failed = ActionQueue::getFailedJobs(ProcessOrder::class);
 * @example
 * // Retry failed job
 * ActionQueue::retryFailedJob($jobId);
 * @example
 * // Clear failed jobs
 * ActionQueue::clearFailedJobs(ProcessOrder::class);
 */
class ActionQueue
{
    /**
     * Get queue status for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Queue status
     */
    public static function getStatus(string $actionClass): array
    {
        $queued = static::getQueuedCount($actionClass);
        $processed = static::getProcessedCount($actionClass);
        $failed = static::getFailedCount($actionClass);

        return [
            'action' => $actionClass,
            'queued' => $queued,
            'processed' => $processed,
            'failed' => $failed,
            'pending' => $queued - $processed,
        ];
    }

    /**
     * Get dashboard data for all queued actions.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::getByTrait(AsJob::class);
        $queueStats = collect();
        $totalQueued = 0;
        $totalProcessed = 0;
        $totalFailed = 0;

        foreach ($actions as $actionClass) {
            $status = static::getStatus($actionClass);
            $queueStats->push($status);
            $totalQueued += $status['queued'];
            $totalProcessed += $status['processed'];
            $totalFailed += $status['failed'];
        }

        return [
            'total_queued' => $totalQueued,
            'total_processed' => $totalProcessed,
            'total_failed' => $totalFailed,
            'actions' => $queueStats->sortByDesc('queued')->values()->toArray(),
        ];
    }

    /**
     * Get failed jobs for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return Collection<array> Failed jobs
     */
    public static function getFailedJobs(string $actionClass): Collection
    {
        if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->where('payload', 'like', "%{$actionClass}%")
            ->orderBy('failed_at', 'desc')
            ->get()
            ->map(fn ($job) => [
                'id' => $job->id,
                'action' => $actionClass,
                'failed_at' => $job->failed_at,
                'exception' => $job->exception,
            ]);
    }

    /**
     * Retry a failed job.
     *
     * @param  int  $jobId  Failed job ID
     */
    public static function retryFailedJob(int $jobId): void
    {
        if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            $job = DB::table('failed_jobs')->where('id', $jobId)->first();
            if ($job) {
                Queue::pushRaw($job->payload);
                DB::table('failed_jobs')->where('id', $jobId)->delete();
            }
        }
    }

    /**
     * Clear failed jobs for an action.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function clearFailedJobs(string $actionClass): void
    {
        if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            DB::table('failed_jobs')
                ->where('payload', 'like', "%{$actionClass}%")
                ->delete();
        }
    }

    /**
     * Get queued count for an action.
     */
    protected static function getQueuedCount(string $actionClass): int
    {
        $key = "queue_stats:{$actionClass}:queued";

        return (int) cache()->get($key, 0);
    }

    /**
     * Get processed count for an action.
     */
    protected static function getProcessedCount(string $actionClass): int
    {
        $key = "queue_stats:{$actionClass}:processed";

        return (int) cache()->get($key, 0);
    }

    /**
     * Get failed count for an action.
     */
    protected static function getFailedCount(string $actionClass): int
    {
        $key = "queue_stats:{$actionClass}:failed";

        return (int) cache()->get($key, 0);
    }

    /**
     * Record a queued job.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function recordQueued(string $actionClass): void
    {
        $key = "queue_stats:{$actionClass}:queued";
        cache()->increment($key);
    }

    /**
     * Record a processed job.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function recordProcessed(string $actionClass): void
    {
        $key = "queue_stats:{$actionClass}:processed";
        cache()->increment($key);
    }

    /**
     * Record a failed job.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function recordFailed(string $actionClass): void
    {
        $key = "queue_stats:{$actionClass}:failed";
        cache()->increment($key);
    }
}
