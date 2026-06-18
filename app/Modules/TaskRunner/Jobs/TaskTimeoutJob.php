<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to handle task timeouts.
 * This job is dispatched when a task is started with a timeout.
 */
class TaskTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     * This should be longer than any task timeout to allow proper monitoring.
     */
    public int $timeout = 900; // 15 minutes - longer than any task timeout

    /**
     * The task model to check for timeout.
     */
    public TaskModel $taskModel;

    /**
     * Create a new job instance.
     */
    public function __construct(TaskModel $taskModel)
    {
        $this->taskModel = $taskModel;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Check if the task model still exists
            if (! $this->taskModel->exists) {
                return;
            }

            // Refresh the task model to get the latest status
            $this->taskModel->refresh();

            // Check if the task is still running
            if ($this->taskModel->status !== TaskStatus::Running) {
                return;
            }

            // Check if the task has exceeded its timeout
            if ($this->hasExceededTimeout()) {
                $this->handleTimeout();
            } else {
            }

        } catch (\Exception $e) {
            // Don't re-throw for model not found errors - just log and return
            if (str_contains($e->getMessage(), 'No query results for model')) {
                return;
            }

            // Re-throw for other errors to trigger retry if needed
            throw $e;
        }
    }

    /**
     * Check if the task has exceeded its timeout.
     */
    protected function hasExceededTimeout(): bool
    {
        if (! $this->taskModel->started_at) {
            return false;
        }

        $timeoutSeconds = $this->getTaskTimeout();
        $elapsedSeconds = now()->diffInSeconds($this->taskModel->started_at);

        return $elapsedSeconds >= $timeoutSeconds;
    }

    /**
     * Get the task timeout in seconds.
     */
    protected function getTaskTimeout(): int
    {
        // Default timeout of 300 seconds (5 minutes) if not specified
        return $this->taskModel->timeout ?? 300;
    }

    /**
     * Handle the timeout by marking the task as failed.
     */
    protected function handleTimeout(): void
    {
        // Update task status to failed
        $this->taskModel->update([
            'status' => TaskStatus::Failed,
            'exit_code' => null,
            'output' => $this->taskModel->output."\n\n[TIMEOUT] Task exceeded timeout of {$this->getTaskTimeout()} seconds",
            'completed_at' => now(),
        ]);

        // Try to kill the running process if possible
        $this->killRunningProcess();

        // Dispatch task failed event
        $this->dispatchTaskFailedEvent();

        // Handle callbacks if the task supports them
        $this->handleCallbacks();
    }

    /**
     * Attempt to kill the running process.
     */
    protected function killRunningProcess(): void
    {
        if (! $this->taskModel->process_id) {
            return;
        }

        try {
            if (extension_loaded('posix')) {
                // Try to kill the process gracefully first
                $killed = posix_kill($this->taskModel->process_id, SIGTERM);

                if ($killed) {
                    // Wait a bit for graceful shutdown
                    sleep(5);

                    // Check if process is still running and force kill if necessary
                    if (posix_kill($this->taskModel->process_id, 0)) {
                        posix_kill($this->taskModel->process_id, SIGKILL);
                    }
                }
            } else {
                // Fallback: use shell command to kill process
                $this->killProcessWithShell();
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Kill process using shell command as fallback.
     */
    protected function killProcessWithShell(): void
    {
        $processId = $this->taskModel->process_id;

        // Try graceful kill first
        exec("kill -TERM {$processId} 2>/dev/null");
        sleep(5);

        // Check if process still exists and force kill
        if (exec("ps -p {$processId} >/dev/null 2>&1") !== false) {
            exec("kill -KILL {$processId} 2>/dev/null");
        }
    }

    /**
     * Dispatch task failed event.
     */
    protected function dispatchTaskFailedEvent(): void
    {
        try {
            // Create a mock task instance for the event
            $task = $this->createTaskInstance();

            if ($task) {
                // Create a mock pending task for the event
                $pendingTask = $task->pending();

                event(new TaskFailed(
                    $task,
                    $pendingTask,
                    null, // output - not available in timeout scenario
                    new \Exception('Task exceeded timeout'),
                    $this->taskModel->started_at ?? now()->toISOString(),
                    'Task exceeded timeout',
                    [
                        'task_model_id' => $this->taskModel->id,
                        'timeout_seconds' => $this->getTaskTimeout(),
                        'elapsed_seconds' => now()->diffInSeconds($this->taskModel->started_at),
                    ]
                ));
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Create a task instance from the model for event dispatching.
     */
    protected function createTaskInstance(): ?object
    {
        if (! $this->taskModel->instance) {
            return null;
        }

        try {
            return unserialize($this->taskModel->instance);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Handle callbacks if the task supports them.
     */
    protected function handleCallbacks(): void
    {
        try {
            $task = $this->createTaskInstance();

            if ($task && method_exists($task, 'handleCallback')) {
                $request = request();
                $task->handleCallback(
                    $this->taskModel,
                    $request,
                    CallbackType::Timeout
                );
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void {}
}
