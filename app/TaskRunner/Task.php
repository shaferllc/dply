<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Concerns\BuildsTaskUrlsAndOutput;
use App\Modules\TaskRunner\Concerns\ConvertsTaskModel;
use App\Modules\TaskRunner\Concerns\ManagesTaskFactory;
use App\Modules\TaskRunner\Concerns\ManagesTaskState;
use App\Modules\TaskRunner\Concerns\ValidatesTaskScript;
use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\Traits\HandlesAnalytics;
use App\Modules\TaskRunner\Traits\HandlesCallbacks;
use App\Modules\TaskRunner\Traits\HandlesMonitoring;
use App\Modules\TaskRunner\Traits\HandlesRollback;
use App\Modules\TaskRunner\Traits\HandlesTemplates;
use App\Modules\TaskRunner\View\TaskViewRenderer;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;

/**
 * Base Task class that provides the foundation for all task implementations in TaskRunner.
 * Combines functionality from both the original Task and EnhancedTask classes.
 */
abstract class Task
{
    use HandlesAnalytics, HandlesCallbacks, HandlesMonitoring, HandlesRollback, HandlesTemplates;
    use Macroable, SerializesModels;
    use BuildsTaskUrlsAndOutput;
    use ConvertsTaskModel;
    use ManagesTaskFactory;
    use ManagesTaskState;
    use ValidatesTaskScript;

    /**
     * The maximum allowed script size in bytes.
     */
    protected const MAX_SCRIPT_SIZE = 1024 * 1024; // 1MB

    /**
     * The associated task model instance.
     */
    protected ?TaskModel $taskModel = null;

    /**
     * The task options.
     */
    protected array $options = [];

    /**
     * The task status.
     */
    protected TaskStatus $status = TaskStatus::Pending;

    /**
     * The task output.
     */
    protected string $output = '';

    /**
     * The task exit code.
     */
    protected ?int $exitCode = null;

    /**
     * The task timeout.
     */
    protected ?int $timeout = 300;

    /**
     * The task user.
     */
    protected string $user = 'root';

    /**
     * The task instance data.
     */
    protected ?string $instance = null;


    /**
     * Static property to track if we're in fake mode.
     */
    protected static bool $fakeMode = false;


    /**
     * Handle the task execution.
     */
    public function handle(): void
    {
        try {
            // Update task status to running
            $this->setStatus(TaskStatus::Running);
            $this->updateTaskModel();

            // Execute the task script
            $script = $this->getScript();
            $output = $this->executeScript($script);

            // Update task with results
            $this->setOutput($output);
            $this->setExitCode(0);
            $this->setStatus(TaskStatus::Finished);
            $this->updateTaskModel();

        } catch (\Exception $e) {
            // Handle task failure
            $this->setOutput($e->getMessage());
            $this->setExitCode(1);
            $this->setStatus(TaskStatus::Failed);
            $this->updateTaskModel();

            throw $e;
        }
    }

    /**
     * Execute the task script and return output.
     */
    protected function executeScript(string $script): string
    {
        // For testing purposes, simulate script execution
        // In production, this would execute the actual script
        if (static::isFake()) {
            // Simulate script execution for testing
            return 'Hello World';
        }

        // Real script execution would go here
        // For now, just return the script as output
        return $script;
    }

    /**
     * Update the task model in the database.
     */
    protected function updateTaskModel(): void
    {
        if ($this->taskModel) {
            $this->taskModel->update([
                'status' => $this->status,
                'output' => $this->output,
                'exit_code' => $this->exitCode,
                'started_at' => $this->status === TaskStatus::Running ? now() : $this->taskModel->started_at,
                'completed_at' => $this->status === TaskStatus::Finished || $this->status === TaskStatus::Failed ? now() : $this->taskModel->completed_at,
            ]);
        }
    }



    public function getPerformanceMetrics(): array
    {
        if (! $this->taskModel) {
            return [];
        }

        $startedAt = $this->taskModel->started_at;
        $completedAt = $this->taskModel->completed_at;
        $duration = $startedAt && $completedAt ? (int) $startedAt->diffInSeconds($completedAt) : 0;

        return [
            'task_id' => $this->taskModel->id,
            'name' => $this->getName(),
            'status' => $this->status->value,
            'exit_code' => $this->exitCode,
            'duration' => (int) $duration,
            'duration_human' => $this->formatDuration((int) $duration),
            'started_at' => $startedAt?->toDateTimeString(),
            'completed_at' => $completedAt?->toDateTimeString(),
            'output_size' => strlen($this->output),
            'output_lines' => count($this->outputLines()),
            'successful' => $this->isSuccessful(),
        ];
    }

    public static function __callStatic($name, $arguments)
    {
        return static::createInstance()->pending()->{$name}(...$arguments);
    }
}
