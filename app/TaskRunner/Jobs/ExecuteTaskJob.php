<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Jobs;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Helper;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\TaskDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ExecuteTaskJob handles task execution through Laravel's queue system.
 * This is ideal for local tasks that don't need shell process execution.
 */
class ExecuteTaskJob implements ShouldQueue
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
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Task $taskModel,
        public ?string $taskClass = null,
        public ?array $taskData = null
    ) {
        $this->onQueue('tasks');
    }

    /**
     * Execute the job.
     */
    public function handle(TaskDispatcher $dispatcher): void
    {
        try {
            Log::info('Starting task execution job', [
                'task_id' => $this->taskModel->id,
                'task_name' => $this->taskModel->name,
                'task_class' => $this->taskClass,
            ]);

            // Update task status to running
            $this->taskModel->update([
                'status' => TaskStatus::Running,
                'started_at' => now(),
            ]);

            // Create the task instance
            if ($this->taskClass && class_exists($this->taskClass)) {
                $task = new $this->taskClass(...($this->taskData ?? []));
                $task->setTaskModel($this->taskModel);
            } else {
                // Fallback to executing the script directly
                $this->executeScriptDirectly();

                return;
            }

            // Execute the task locally (not in background)
            $result = $dispatcher->runWithModel($task, $this->taskModel);

            // Update task with results
            $this->taskModel->update([
                'status' => $result && $result->isSuccessful()
                    ? TaskStatus::Finished
                    : TaskStatus::Failed,
                'exit_code' => $result ? $result->getExitCode() : 1,
                'output' => $result ? $result->getBuffer() : 'Task execution failed',
                'completed_at' => now(),
            ]);

            Log::info('Task execution job completed', [
                'task_id' => $this->taskModel->id,
                'success' => $result && $result->isSuccessful(),
                'exit_code' => $result ? $result->getExitCode() : null,
            ]);

        } catch (\Exception $e) {
            Log::error('Task execution job failed', [
                'task_id' => $this->taskModel->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark task as failed
            $this->taskModel->update([
                'status' => TaskStatus::Failed,
                'exit_code' => 1,
                'output' => 'Task execution failed: '.$e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Execute the script directly if no task class is available.
     */
    private function executeScriptDirectly(): void
    {
        $script = $this->taskModel->script;
        if (! $script) {
            throw new \Exception('No script available to execute');
        }

        // Remove the EOF marker
        $script = str_replace(Helper::eof(), '', $script);

        // Execute the script
        $output = [];
        $exitCode = 0;

        exec($script.' 2>&1', $output, $exitCode);

        $outputString = implode("\n", $output);

        $this->taskModel->update([
            'status' => $exitCode === 0
                ? TaskStatus::Finished
                : TaskStatus::Failed,
            'exit_code' => $exitCode,
            'output' => $outputString,
            'completed_at' => now(),
        ]);
    }
}
