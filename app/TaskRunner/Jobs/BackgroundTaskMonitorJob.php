<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\BackgroundTaskTracker;
use App\Modules\TaskRunner\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * BackgroundTaskMonitorJob handles monitoring tasks in the background.
 * This job is queued to periodically check task status and send callbacks.
 */
class BackgroundTaskMonitorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $taskId,
        protected string $taskClass
    ) {
        $this->onQueue('task-monitoring');
    }

    /**
     * Execute the job.
     */
    public function handle(BackgroundTaskTracker $tracker): void
    {
        try {
            $task = Task::find($this->taskId);

            if (! $task) {
                Log::warning('Background task not found for monitoring', [
                    'task_id' => $this->taskId,
                ]);

                return;
            }

            // Create task instance
            $taskInstance = $this->createTaskInstance($task);

            if (! $taskInstance) {
                Log::error('Failed to create task instance for monitoring', [
                    'task_id' => $this->taskId,
                    'task_class' => $this->taskClass,
                ]);

                return;
            }

            // Monitor the task
            $tracker->monitorTask($task, $taskInstance);

        } catch (\Exception $e) {
            Log::error('Error in background task monitoring job', [
                'task_id' => $this->taskId,
                'task_class' => $this->taskClass,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create task instance from class name.
     */
    protected function createTaskInstance(Task $task): ?object
    {
        try {
            if (! class_exists($this->taskClass)) {
                Log::error('Task class does not exist', [
                    'task_class' => $this->taskClass,
                ]);

                return null;
            }

            // Handle TrackTaskInBackground specially since it requires constructor arguments
            if ($this->taskClass === TrackTaskInBackground::class) {
                return $this->createTrackTaskInBackgroundInstance($task);
            }

            $instance = new $this->taskClass;

            // Set task model if method exists
            if (method_exists($instance, 'setTaskModel')) {
                $instance->setTaskModel($task);
            }

            return $instance;

        } catch (\Exception $e) {
            Log::error('Failed to create task instance', [
                'task_class' => $this->taskClass,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Create TrackTaskInBackground instance from stored data.
     */
    protected function createTrackTaskInBackgroundInstance(Task $task): ?TrackTaskInBackground
    {
        try {
            $options = $task->options ?? [];

            // Extract callback URLs from options
            $finishedUrl = $options['finished_url'] ?? 'https://example.com/finished';
            $failedUrl = $options['failed_url'] ?? 'https://example.com/failed';
            $timeoutUrl = $options['timeout_url'] ?? 'https://example.com/timeout';

            // Get the actual task class from options
            $actualTaskClass = $options['actual_task_class'] ?? TestTask::class;

            if (! class_exists($actualTaskClass)) {
                Log::error('Actual task class does not exist', [
                    'actual_task_class' => $actualTaskClass,
                ]);

                return null;
            }

            // Create the actual task instance
            $actualTask = new $actualTaskClass;

            // Set task model on the actual task
            if (method_exists($actualTask, 'setTaskModel')) {
                $actualTask->setTaskModel($task);
            }

            // Create TrackTaskInBackground instance with the required arguments
            $trackTask = new TrackTaskInBackground(
                $actualTask,
                $finishedUrl,
                $failedUrl,
                $timeoutUrl
            );

            // Set task model on the tracking task
            $trackTask->setTaskModel($task);

            return $trackTask;

        } catch (\Exception $e) {
            Log::error('Failed to create TrackTaskInBackground instance', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Background task monitoring job failed', [
            'task_id' => $this->taskId,
            'task_class' => $this->taskClass,
            'error' => $exception->getMessage(),
        ]);
    }
}
