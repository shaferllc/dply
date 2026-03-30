<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Services;

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Jobs\BackgroundTaskMonitorJob;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * BackgroundTaskTracker service handles tracking tasks in the background
 * with comprehensive callback support and real-time monitoring.
 */
class BackgroundTaskTracker
{
    /**
     * Create a new BackgroundTaskTracker instance.
     */
    public function __construct(
        protected readonly CallbackService $callbackService,
        protected readonly ?StreamingLoggerInterface $streamingLogger = null
    ) {
        // Resolve streaming logger if not provided
        if ($this->streamingLogger === null && app()->bound(StreamingLoggerInterface::class)) {
            $this->streamingLogger = app(StreamingLoggerInterface::class);
        }
    }

    /**
     * Start tracking a task in the background.
     */
    public function startTracking(Task $task, HasCallbacks $taskInstance): void
    {
        // Update task status to running
        $task->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
        ]);

        // Send started callback
        $this->sendStartedCallback($taskInstance, $task);

        // Stream task started event
        $this->streamTaskEvent('started', [
            'task_id' => $task->id,
            'task_name' => $task->name,
        ]);

        // Schedule background monitoring
        $this->scheduleMonitoring($task, $taskInstance);

        Log::info('Background task tracking started', [
            'task_id' => $task->id,
            'task_name' => $task->name,
        ]);
    }

    /**
     * Monitor task progress in the background.
     */
    public function monitorTask(Task $task, HasCallbacks $taskInstance): void
    {
        try {
            // Check if task is still running
            if ($task->isFinished()) {
                $this->handleTaskCompletion($task, $taskInstance);

                return;
            }

            // Check for timeout
            if ($task->isTimedOut()) {
                $this->handleTaskTimeout($task, $taskInstance);

                return;
            }

            // Send progress callback if configured
            $this->sendProgressCallback($taskInstance, $task);

            // Stream progress update
            $this->streamTaskEvent('progress', [
                'task_id' => $task->id,
                'progress' => $this->calculateProgress($task),
                'output' => $task->getOutput(),
            ]);

            // Schedule next monitoring check
            $this->scheduleNextMonitoring($task, $taskInstance);

        } catch (\Exception $e) {
            Log::error('Error monitoring background task', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            $this->handleTaskFailure($task, $taskInstance, $e->getMessage());
        }
    }

    /**
     * Handle task completion.
     */
    public function handleTaskCompletion(Task $task, HasCallbacks $taskInstance): void
    {
        $task->update([
            'status' => TaskStatus::Finished,
            'completed_at' => now(),
            'exit_code' => $task->exit_code ?? 0,
        ]);

        // Send completion callback
        $this->sendCompletionCallback($taskInstance, $task);

        // Stream completion event
        $this->streamTaskEvent('completed', [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'exit_code' => $task->exit_code,
            'duration' => $task->getDuration(),
        ]);

        Log::info('Background task completed', [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'exit_code' => $task->exit_code,
            'duration' => $task->getDuration(),
        ]);
    }

    /**
     * Handle task timeout.
     */
    public function handleTaskTimeout(Task $task, HasCallbacks $taskInstance): void
    {
        $task->update([
            'status' => TaskStatus::Timeout,
            'timed_out_at' => now(),
        ]);

        // Send timeout callback
        $this->sendTimeoutCallback($taskInstance, $task);

        // Stream timeout event
        $this->streamTaskEvent('timeout', [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'timeout_duration' => $task->timeout,
        ]);

        Log::warning('Background task timed out', [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'timeout_duration' => $task->timeout,
        ]);
    }

    /**
     * Handle task failure.
     */
    public function handleTaskFailure(Task $task, HasCallbacks $taskInstance, ?string $error = null): void
    {
        $task->update([
            'status' => TaskStatus::Failed,
            'failed_at' => now(),
            'error_message' => $error,
        ]);

        // Send failure callback
        $this->sendFailureCallback($taskInstance, $task, $error);

        // Stream failure event
        $this->streamTaskEvent('failed', [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'error' => $error,
        ]);

        Log::error('Background task failed', [
            'task_id' => $task->id,
            'task_name' => $task->name,
            'error' => $error,
        ]);
    }

    /**
     * Send started callback.
     */
    protected function sendStartedCallback(HasCallbacks $taskInstance, Task $task): void
    {
        if ($taskInstance->isCallbacksEnabled()) {
            $taskInstance->sendCallback(CallbackType::Started, [
                'event' => 'task_started',
                'started_at' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Send completion callback.
     */
    protected function sendCompletionCallback(HasCallbacks $taskInstance, Task $task): void
    {
        if ($taskInstance->isCallbacksEnabled()) {
            $taskInstance->sendCallback(CallbackType::Finished, [
                'event' => 'task_finished',
                'completed_at' => now()->toISOString(),
                'success' => true,
            ]);
        }
    }

    /**
     * Send timeout callback.
     */
    protected function sendTimeoutCallback(HasCallbacks $taskInstance, Task $task): void
    {
        if ($taskInstance->isCallbacksEnabled()) {
            $taskInstance->sendCallback(CallbackType::Timeout, [
                'event' => 'task_timeout',
                'timed_out_at' => now()->toISOString(),
                'timeout_duration' => $task->timeout,
            ]);
        }
    }

    /**
     * Send failure callback.
     */
    protected function sendFailureCallback(HasCallbacks $taskInstance, Task $task, ?string $error = null): void
    {
        if ($taskInstance->isCallbacksEnabled()) {
            $taskInstance->sendCallback(CallbackType::Failed, [
                'event' => 'task_failed',
                'failed_at' => now()->toISOString(),
                'success' => false,
                'error' => $error,
            ]);
        }
    }

    /**
     * Send progress callback.
     */
    protected function sendProgressCallback(HasCallbacks $taskInstance, Task $task): void
    {
        if ($taskInstance->isCallbacksEnabled()) {
            $progress = $this->calculateProgress($task);

            $taskInstance->sendCallback(CallbackType::Progress, [
                'event' => 'task_progress',
                'progress_at' => now()->toISOString(),
                'percentage' => $progress,
                'output' => $task->getOutput(),
                'duration' => $task->getDuration(),
            ]);
        }
    }

    /**
     * Schedule monitoring for the task.
     */
    protected function scheduleMonitoring(Task $task, HasCallbacks $taskInstance): void
    {
        $monitoringInterval = config('task-runner.background.monitoring_interval', 5);

        Queue::later(
            now()->addSeconds($monitoringInterval),
            new BackgroundTaskMonitorJob($task->id, get_class($taskInstance))
        );
    }

    /**
     * Schedule next monitoring check.
     */
    protected function scheduleNextMonitoring(Task $task, HasCallbacks $taskInstance): void
    {
        $monitoringInterval = config('task-runner.background.monitoring_interval', 5);

        Queue::later(
            now()->addSeconds($monitoringInterval),
            new BackgroundTaskMonitorJob($task->id, get_class($taskInstance))
        );
    }

    /**
     * Calculate task progress percentage.
     */
    protected function calculateProgress(Task $task): int
    {
        // Default progress calculation based on time elapsed
        $startedAt = $task->started_at;
        $timeout = $task->timeout;

        if (! $startedAt || ! $timeout) {
            return 0;
        }

        $elapsed = now()->diffInSeconds($startedAt);
        $progress = min(95, round(($elapsed / $timeout) * 100));

        return (int) $progress;
    }

    /**
     * Stream a task event.
     */
    protected function streamTaskEvent(string $event, array $context = []): void
    {
        if ($this->streamingLogger && method_exists($this->streamingLogger, 'streamTaskEvent')) {
            $this->streamingLogger->streamTaskEvent($event, $context);
        }
    }

    /**
     * Get task status summary.
     */
    public function getTaskStatus(Task $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'status' => $task->status->value,
            'started_at' => $task->started_at?->toISOString(),
            'completed_at' => $task->completed_at?->toISOString(),
            'failed_at' => $task->failed_at?->toISOString(),
            'timed_out_at' => $task->timed_out_at?->toISOString(),
            'duration' => $task->getDuration(),
            'exit_code' => $task->exit_code,
            'error_message' => $task->error_message,
            'output' => $task->getOutput(),
            'progress' => $this->calculateProgress($task),
        ];
    }

    /**
     * Cancel background task tracking.
     */
    public function cancelTracking(Task $task, HasCallbacks $taskInstance): void
    {
        $task->update([
            'status' => TaskStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        // Send cancellation callback
        if ($taskInstance->isCallbacksEnabled()) {
            $taskInstance->sendCallback(CallbackType::Custom, [
                'event' => 'task_cancelled',
                'cancelled_at' => now()->toISOString(),
            ]);
        }

        // Stream cancellation event
        $this->streamTaskEvent('cancelled', [
            'task_id' => $task->id,
            'task_name' => $task->name,
        ]);

        Log::info('Background task tracking cancelled', [
            'task_id' => $task->id,
            'task_name' => $task->name,
        ]);
    }

    /**
     * Get all running background tasks.
     */
    public function getRunningTasks(): array
    {
        return Task::where('status', TaskStatus::Running)
            ->whereNotNull('started_at')
            ->get()
            ->map(fn ($task) => $this->getTaskStatus($task))
            ->toArray();
    }

    /**
     * Clean up old completed tasks.
     */
    public function cleanupOldTasks(int $daysToKeep = 30): int
    {
        $cutoffDate = now()->subDays($daysToKeep);

        $deletedCount = Task::whereIn('status', [
            TaskStatus::Finished,
            TaskStatus::Failed,
            TaskStatus::Timeout,
            TaskStatus::Cancelled,
        ])
            ->where('created_at', '<', $cutoffDate)
            ->delete();

        Log::info('Cleaned up old background tasks', [
            'deleted_count' => $deletedCount,
            'cutoff_date' => $cutoffDate->toISOString(),
        ]);

        return $deletedCount;
    }
}
