<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

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
     * Returns the name of the task.
     */
    public function getName(): string
    {
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('name')) {
            $property = $reflection->getProperty('name');
            $property->setAccessible(true);
            $value = $property->getValue($this);
            if ($value !== null) {
                return $value;
            }
        }

        return Str::headline(class_basename($this));
    }

    /**
     * Returns the action of the task.
     */
    public function getAction(): string
    {
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('action')) {
            $property = $reflection->getProperty('action');
            $property->setAccessible(true);
            $value = $property->getValue($this);
            if ($value !== null) {
                return $value;
            }
        }

        return Str::snake(class_basename($this));
    }

    /**
     * Returns the timeout of the task in seconds.
     */
    public function getTimeout(): ?int
    {
        $timeout = null;
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('timeout')) {
            $property = $reflection->getProperty('timeout');
            $property->setAccessible(true);
            $timeout = $property->getValue($this);
        }

        if ($timeout === null) {
            $timeout = config('task-runner.default_timeout', 60);
        }

        return $timeout > 0 ? $timeout : null;
    }

    /**
     * Returns the view name of the task.
     */
    public function getView(): string
    {
        $view = null;
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('view')) {
            $property = $reflection->getProperty('view');
            $property->setAccessible(true);
            $view = $property->getValue($this);
        }

        if ($view === null) {
            $view = Str::kebab(class_basename($this));
        }

        if (config('task-runner.task_views') !== null) {
            $prefix = rtrim(config('task-runner.task_views'), '');

            return $prefix ? $prefix.'::'.$view : $view;
        }

        return $view;
    }

    /**
     * Set the task model instance.
     */
    public function setTaskModel(TaskModel $taskModel): self
    {
        $this->taskModel = $taskModel;

        return $this;
    }

    /**
     * Get the task model instance.
     */
    public function getTaskModel(): ?TaskModel
    {
        return $this->taskModel;
    }

    /**
     * Set the task options.
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Get the task options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set a specific option.
     */
    public function setOption(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * Get a specific option.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set the task status.
     */
    public function setStatus(TaskStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get the task status.
     */
    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    /**
     * Set the task output.
     */
    public function setOutput(string $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Get the task output.
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * Set the task exit code.
     */
    public function setExitCode(?int $exitCode): self
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    /**
     * Get the task exit code.
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Set the task timeout.
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set the task user.
     */
    public function setUser(string $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get the task user.
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * Set the task instance data.
     */
    public function setInstance(?string $instance): self
    {
        $this->instance = $instance;

        return $this;
    }

    /**
     * Get the task instance data.
     */
    public function getInstance(): ?string
    {
        return $this->instance;
    }

    /**
     * Check if the task is finished.
     */
    public function isFinished(): bool
    {
        return $this->status === TaskStatus::Finished;
    }

    /**
     * Check if the task is pending.
     */
    public function isPending(): bool
    {
        return $this->status === TaskStatus::Pending;
    }

    /**
     * Check if the task has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === TaskStatus::Failed;
    }

    /**
     * Check if the task has timed out.
     */
    public function isTimedOut(): bool
    {
        return $this->status === TaskStatus::Timeout;
    }

    /**
     * Check if the task was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->isFinished() && $this->exitCode === 0;
    }

    /**
     * Called when task output is updated.
     */
    public function onOutputUpdated(string $output): void
    {
        $this->output = $output;

        if ($this->taskModel) {
            $this->taskModel->update(['output' => $output]);
        }
    }

    /**
     * Get the callback URL for the task.
     */
    public function callbackUrl(): ?string
    {
        if (! $this->taskModel || ! $this->taskModel->id) {
            return null;
        }

        return $this instanceof HasCallbacks ? $this->taskModel->callbackUrl() : null;
    }

    /**
     * Get the timeout URL for the task.
     */
    public function timeoutUrl(): ?string
    {
        return $this->taskModel?->timeoutUrl();
    }

    /**
     * Get the failed URL for the task.
     */
    public function failedUrl(): ?string
    {
        return $this->taskModel?->failedUrl();
    }

    /**
     * Get the finished URL for the task.
     */
    public function finishedUrl(): ?string
    {
        return $this->taskModel?->finishedUrl();
    }

    /**
     * Generate a step name from the class name.
     */
    public function stepName(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * Get the task output as lines.
     */
    public function outputLines(): array
    {
        if (empty($this->output)) {
            return [];
        }

        return explode(PHP_EOL, $this->output);
    }

    /**
     * Get the last N lines of output.
     */
    public function tailOutput(int $lines = 10): string
    {
        $outputLines = $this->outputLines();
        $tailLines = array_slice($outputLines, -$lines);

        return implode(PHP_EOL, $tailLines);
    }

    /**
     * Get filtered output (without debug lines).
     */
    public function getFilteredOutput(): string
    {
        if (empty($this->output)) {
            return '';
        }

        $lines = [];
        $currentLines = preg_split('/\r\n|\r|\n/', $this->output);

        if ($currentLines === false) {
            return '';
        }

        foreach ($currentLines as $line) {
            if (! Str::startsWith($line, '+')) {
                $lines[] = $line;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Check if the task is older than its timeout.
     */
    public function isOlderThanTimeout(): bool
    {
        if (! $this->taskModel || ! $this->taskModel->created_at) {
            return false;
        }

        return $this->taskModel->created_at->copy()->addSeconds($this->timeout)->isPast();
    }

    /**
     * Get the task output log path on the server.
     */
    public function outputLogPath(): string
    {
        if (! $this->taskModel || ! $this->taskModel->server) {
            return '';
        }

        $directory = $this->user === 'root'
            ? $this->taskModel->server->connectionAsRoot()->scriptPath
            : $this->taskModel->server->connectionAsUser()->scriptPath;

        return "{$directory}/task-{$this->taskModel->id}.log";
    }

    /**
     * Update the task output from the server.
     */
    public function updateOutput(bool $handleCallbacks = true): self
    {
        if (! $this->taskModel || ! $this->taskModel->server) {
            return $this;
        }

        try {
            // Use the new TaskRunner to get the output
            $getFileTask = AnonymousTask::command('Get Task Output', "cat {$this->outputLogPath()}");
            $pendingTask = $getFileTask->pending()->onConnection($this->taskModel->server->connectionAsRoot());

            $output = app(TaskDispatcher::class)->run($pendingTask);

            if ($output && $output->isSuccessful()) {
                $this->output = $output->getBuffer();
                $this->taskModel->update(['output' => $this->output]);

                if ($handleCallbacks && $this instanceof HasCallbacks) {
                    $this->onOutputUpdated($this->output);
                }
            }
        } catch (\Exception $e) {
            // Log the error but don't throw
            Log::error('Failed to update task output', [
                'task_id' => $this->taskModel->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return $this;
    }

    /**
     * Update output without handling callbacks.
     */
    public function updateOutputWithoutCallbacks(): self
    {
        return $this->updateOutput(handleCallbacks: false);
    }

    /**
     * Update output in the background.
     */
    public function updateOutputInBackground(): self
    {
        if ($this->taskModel) {
            // Dispatch a job to update the output
            UpdateTaskOutput::dispatch($this->taskModel)
                ->onQueue('task-output');
        }

        return $this;
    }

    /**
     * Get the task as a model instance.
     */
    public function toModel(): TaskModel
    {
        if ($this->taskModel) {
            return $this->taskModel;
        }

        // Create a new model instance
        $model = new TaskModel;
        $model->fill([
            'name' => $this->getName(),
            'action' => $this->getAction(),
            'script' => $this->getScript(),
            'timeout' => $this->timeout,
            'user' => $this->user,
            'status' => $this->status,
            'output' => $this->output,
            'exit_code' => $this->exitCode,
            'options' => $this->options,
            'instance' => serialize($this),
        ]);

        return $model;
    }

    /**
     * Create a task from a model instance.
     */
    public static function fromModel(TaskModel $model): static
    {
        $task = new static;
        $task->taskModel = $model;
        $task->status = $model->status;
        $task->output = $model->output ?? '';
        $task->exitCode = $model->exit_code;
        $task->timeout = $model->timeout ?? 300;
        $task->user = $model->user ?? 'root';
        $task->options = $model->options ?? [];

        return $task;
    }

    /**
     * Get the task performance metrics.
     */
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

    /**
     * Format duration in a human-readable format.
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        if ($seconds < 60) {
            return round($seconds, 2).'s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return "{$minutes}m ".round($remainingSeconds, 2).'s';
    }

    /**
     * Get the task summary.
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->taskModel?->id,
            'name' => $this->getName(),
            'action' => $this->getAction(),
            'status' => $this->status->value,
            'exit_code' => $this->exitCode,
            'user' => $this->user,
            'timeout' => $this->timeout,
            'output_size' => strlen($this->output),
            'output_lines' => count($this->outputLines()),
            'successful' => $this->isSuccessful(),
            'finished' => $this->isFinished(),
            'failed' => $this->isFailed(),
            'timed_out' => $this->isTimedOut(),
            'options' => $this->options,
            'performance' => $this->getPerformanceMetrics(),
        ];
    }

    /**
     * Returns all public properties of the task.
     *
     * @return Collection<string, mixed>
     */
    public function getPublicProperties(): Collection
    {
        $properties = (new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC);

        return Collection::make($properties)->mapWithKeys(function (ReflectionProperty $property) {
            return [$property->getName() => $property->getValue($this)];
        });
    }

    /**
     * Returns all public methods of the task.
     *
     * @return Collection<int|string, Closure|null>
     */
    public function getPublicMethods(): Collection
    {
        $macros = Collection::make(static::$macros)
            ->mapWithKeys(function ($macro, $name) {
                return [$name => Closure::bind($macro, $this, get_class($this))];
            });

        $methods = (new ReflectionObject($this))->getMethods(ReflectionProperty::IS_PUBLIC);

        $methodCollection = Collection::make($methods)
            ->filter(function (ReflectionMethod $method) {
                if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                    return false;
                }

                $ignoreMethods = [
                    'getAction',
                    'getData',
                    'getScript',
                    'getName',
                    'getTimeout',
                    'getView',
                    'validate',
                    'getPublicProperties',
                    'getPublicMethods',
                    'getViewData',
                ];

                if (in_array($method->getName(), $ignoreMethods)) {
                    return false;
                }

                return true;
            })
            ->mapWithKeys(function (ReflectionMethod $method) {
                return [$method->getName() => $method->getClosure($this)];
            });

        return $macros->merge($methodCollection);
    }

    /**
     * Returns all data that should be passed to the view.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->getPublicProperties()
            ->merge($this->getPublicMethods())
            ->merge($this->getViewData())
            ->all();
    }

    /**
     * Returns the data that should be passed to the view.
     *
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return [];
    }

    /**
     * Validates the task before execution.
     *
     * @throws TaskValidationException
     */
    public function validate(): void
    {
        $errors = [];

        // Validate timeout
        $timeout = $this->getTimeout();
        if ($timeout !== null && ($timeout < 1 || $timeout > 3600)) {
            $errors['timeout'] = 'Timeout must be between 1 and 3600 seconds.';
        }

        // Validate view exists
        if (! method_exists($this, 'render') && ! view()->exists($this->getView())) {
            $errors['view'] = "View '{$this->getView()}' does not exist.";
        }

        // Validate public properties
        $this->getPublicProperties()->each(function ($value, $key) use (&$errors) {
            if (is_string($value) && strlen($value) > 10000) {
                $errors["property.{$key}"] = "Property '{$key}' value is too long (max 10000 characters).";
            }
        });

        if (! empty($errors)) {
            throw TaskValidationException::withErrors($errors);
        }
    }

    /**
     * Validates the generated script for security concerns.
     *
     * @throws TaskValidationException
     */
    protected function validateScript(string $script): void
    {
        $errors = [];

        // Check script size
        if (strlen($script) > self::MAX_SCRIPT_SIZE) {
            $errors['script_size'] = 'Script is too large (max '.(self::MAX_SCRIPT_SIZE / 1024).'KB).';
        }

        // Check for forbidden commands
        $forbiddenCommands = config('task-runner.security.forbidden_commands', []);
        foreach ($forbiddenCommands as $command) {
            if (stripos($script, $command) !== false) {
                $errors['forbidden_command'] = "Script contains forbidden command: {$command}";
                break;
            }
        }

        // Check for potentially dangerous patterns
        $dangerousPatterns = [
            '/\$\{.*\}/', // Variable expansion
            '/\$\(.*\)/', // Command substitution
            '/`.*`/',     // Backtick command substitution
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $script)) {
                $errors['dangerous_pattern'] = 'Script contains potentially dangerous patterns.';
                break;
            }
        }

        if (! empty($errors)) {
            throw TaskValidationException::withErrors($errors);
        }
    }

    /**
     * Returns the rendered script.
     */
    public function getScript(): string
    {
        // Validate the task before generating script
        $this->validate();

        $script = '';

        try {
            if (method_exists($this, 'render')) {
                $script = Container::getInstance()->call([$this, 'render']);
            } else {
                // Use the enhanced view renderer for complex views
                $renderer = new TaskViewRenderer($this);
                $script = $renderer->render();
            }
        } catch (\Throwable $e) {
            throw new TaskValidationException(
                'Failed to generate script: '.$e->getMessage(),
                ['script_generation' => $e->getMessage()]
            );
        }

        // Validate the generated script
        $this->validateScript($script);

        return $script;
    }

    /**
     * Returns a new PendingTask with this task.
     */
    public function pending(): PendingTask
    {
        return new PendingTask($this);
    }

    /**
     * Returns a new PendingTask with this task.
     */
    public static function make(...$arguments): PendingTask
    {
        return static::createInstance(...$arguments)->pending();
    }

    /**
     * Helper methods to create a new PendingTask.
     *
     * @param  string  $name  The name of the method.
     *
     * @see Task::make()
     * @see Task::pending()
     */
    public static function __callStatic($name, $arguments)
    {
        return static::createInstance()->pending()->{$name}(...$arguments);
    }

    /**
     * Create an instance of the current class.
     */
    protected static function createInstance(...$arguments): static
    {
        // If we're in fake mode, create a fake instance
        if (static::isFake()) {
            return static::createFakeInstance(...$arguments);
        }

        return new (static::class)(...$arguments);
    }

    /**
     * Static property to track if we're in fake mode.
     */
    protected static bool $fakeMode = false;

    /**
     * Enable fake mode for testing.
     */
    public static function fake(): void
    {
        static::$fakeMode = true;
    }

    /**
     * Disable fake mode.
     */
    public static function unfake(): void
    {
        static::$fakeMode = false;
    }

    /**
     * Check if we're in fake mode.
     */
    public static function isFake(): bool
    {
        return static::$fakeMode;
    }

    /**
     * Check if background tracking should be disabled (for fake mode).
     */
    public static function shouldDisableBackgroundTracking(): bool
    {
        return static::isFake();
    }

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

    /**
     * Create a fake instance for testing that sets up the task model
     * and allows running the actual task code without background monitoring.
     */
    protected static function createFakeInstance(...$arguments): static
    {
        $task = static::createInstance(...$arguments);

        // Create a task model for testing
        $taskModel = TaskModel::create([
            'name' => $task->getName(),
            'action' => $task->getAction(),
            'status' => TaskStatus::Pending,
            'script' => $task->getScript(),
            'options' => json_encode($task->getOptions()),
            'timeout' => $task->getTimeout(),
            'user' => $task->getUser(),
            'created_by' => null,
        ]);

        // Set the task model on the task
        $task->setTaskModel($taskModel);

        return $task;
    }

    /**
     * Convert this task to an AnonymousTask for enhanced TaskRunner features.
     */
    public function toAnonymousTask(): AnonymousTask
    {
        return AnonymousTask::script(
            $this->getName(),
            $this->getScript(),
            $this->getOptions()
        );
    }

    /**
     * Check if this task is compatible with enhanced TaskRunner features.
     */
    public function isEnhancedCompatible(): bool
    {
        return method_exists($this, 'render') || method_exists($this, 'getScript');
    }

    /**
     * Get task information for monitoring and management.
     */
    public function getTaskInfo(): array
    {
        return [
            'class' => static::class,
            'name' => $this->getName(),
            'compatible' => $this->isEnhancedCompatible(),
            'has_callbacks' => $this instanceof HasCallbacks,
            'has_options' => ! empty($this->options),
            'has_task_model' => $this->taskModel !== null,
        ];
    }

    /**
     * Render the task's script as a string using the associated Blade view.
     * Useful for validation, preview, or testing.
     *
     * @param  array  $viewData  Optional overrides for view data.
     */
    protected function renderScript(array $viewData = []): string
    {
        $data = array_merge(
            $this->getData(),
            $viewData
        );

        return view($this->getView(), $data)->render();
    }
}
