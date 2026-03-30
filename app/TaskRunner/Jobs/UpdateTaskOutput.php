<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to update task output in the background.
 */
class UpdateTaskOutput implements ShouldQueue
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
        public Task $task,
        public int $dispatchNewJobAfter = 0,
        public int $updateCount = 0
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Check if the task model still exists
            if (! $this->task->exists) {
                return;
            }

            // Limit the number of updates to prevent infinite loops (max 60 updates = 10 minutes at 10s intervals)
            if ($this->updateCount >= 60) {
                return;
            }

            // Store the current output length before update
            $previousOutputLength = strlen($this->task->output ?? '');

            // Update the task output
            $this->task->updateOutput();

            // Check if the task has completed
            if (! $this->task->isRunning()) {
                // Dispatch the TaskCompleted event
                $this->dispatchTaskCompletedEvent();

                return;
            }

            // Check if there's new output
            $currentOutputLength = strlen($this->task->output ?? '');
            $hasNewOutput = $currentOutputLength > $previousOutputLength;

            // Only dispatch a new job if:
            // 1. The task is still running
            // 2. We haven't reached the update limit
            if ($this->dispatchNewJobAfter > 0 && $this->task->isRunning() && $this->updateCount < 60) {
                if ($hasNewOutput || $this->updateCount < 5) {
                    // If there's new output or early in the process, continue with normal frequency
                    static::dispatch($this->task, $this->dispatchNewJobAfter, $this->updateCount + 1)
                        ->onQueue('task-output')
                        ->delay(now()->addSeconds($this->dispatchNewJobAfter));
                } else {
                    // If no new output for a while, check less frequently
                    static::dispatch($this->task, 30, $this->updateCount + 1)
                        ->onQueue('task-output')
                        ->delay(now()->addSeconds(30));
                }
            }
        } catch (\Exception $e) {
            // Don't re-throw for model not found errors - just log and return
            if (str_contains($e->getMessage(), 'No query results for model')) {
                return;
            }

            // Re-throw for other errors to trigger retry
            throw $e;
        }
    }

    /**
     * Dispatch the TaskCompleted event for a completed task.
     */
    private function dispatchTaskCompletedEvent(): void
    {
        try {
            // Get the task instance from the model
            $taskInstance = $this->task->getTaskInstance();
            if (! $taskInstance) {
                return;
            }

            // Create a PendingTask
            $pendingTask = new PendingTask($taskInstance);

            // Create ProcessOutput from task data
            $output = new ProcessOutput(
                $this->task->output ?? '',
                $this->task->exit_code ?? 0,
                false
            );

            // Create the TaskCompleted event
            $event = new TaskCompleted(
                $taskInstance,
                $pendingTask,
                $output,
                $this->task->started_at?->toISOString() ?? now()->toISOString(),
                [
                    'task_model_id' => $this->task->id,
                    'server_id' => $this->task->server_id,
                    'user' => $this->task->user,
                ]
            );

            // Dispatch the event
            event($event);

        } catch (\Exception $e) {
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('UpdateTaskOutput job failed permanently', [
            'task_id' => $this->task->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
