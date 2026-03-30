<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Contracts\TaskDispatcherInterface;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\Events\TaskStarted;
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use App\Modules\TaskRunner\Jobs\ExecuteTaskJob;
use App\Modules\TaskRunner\Jobs\TaskTimeoutJob;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\Traits\MakesTestAssertions;
use App\Modules\TaskRunner\Traits\PersistsFakeTasks;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process as FacadesProcess;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * TaskDispatcher that provides comprehensive task execution capabilities.
 * Combines functionality from both the original TaskDispatcher and EnhancedTaskDispatcher classes.
 */
class TaskDispatcher implements TaskDispatcherInterface
{
    use MakesTestAssertions, PersistsFakeTasks;

    private const DEFAULT_TIMEOUT = 10;

    private const SCRIPT_EXTENSION = '.sh';

    private const LOG_EXTENSION = '.log';

    /**
     * @var array<int, mixed>|bool
     */
    protected array|bool $tasksToFake = false;

    /**
     * @var array<int, mixed>
     */
    protected array $tasksToDispatch = [];

    /**
     * @var array<int, mixed>
     */
    protected array $dispatchedTasks = [];

    protected bool $preventStrayTasks = false;

    public function __construct(
        protected readonly ProcessRunner $processRunner,
        protected readonly ?int $defaultTimeout = null
    ) {}

    public function run(PendingTask $pendingTask): ?ProcessOutput
    {
        $startedAt = now()->toISOString();
        $context = ['dispatched_at' => $startedAt];

        try {
            // Dispatch task started event
            event(new TaskStarted($pendingTask->task, $pendingTask, $context));

            if ($fakeTask = $this->taskShouldBeFaked($pendingTask)) {
                $result = $this->handleFakeTask($pendingTask, $fakeTask);

                // Dispatch task completed event for fake tasks
                if ($result) {
                    event(new TaskCompleted($pendingTask->task, $pendingTask, $result, $startedAt, $context));
                }

                return $result;
            }

            $result = null;
            if ($pendingTask->getConnection()) {
                $result = $this->runOnConnection($pendingTask);
            } else {
                $result = $pendingTask->shouldRunInBackground()
                    ? $this->runInBackground($pendingTask)
                    : $this->runLocally($pendingTask);
            }

            // Dispatch task completed event
            if ($result) {
                event(new TaskCompleted($pendingTask->task, $pendingTask, $result, $startedAt, $context));
            }

            return $result;
        } catch (\Exception $e) {
            // Dispatch task failed event
            event(new TaskFailed(
                $pendingTask->task,
                $pendingTask,
                null,
                $e,
                $startedAt,
                'Task execution failed: '.$e->getMessage(),
                $context
            ));

            $detail = trim($e->getMessage());
            $summary = 'Failed to execute task: '.get_class($pendingTask->task);
            if ($detail !== '') {
                $summary .= ': '.$detail;
            }

            throw new TaskExecutionException(
                $summary,
                previous: $e
            );
        }
    }

    /**
     * Run a task with full integration and model support.
     */
    public function runWithModel(Task $task, ?TaskModel $taskModel = null): ?ProcessOutput
    {
        // Create or use existing task model
        if (! $taskModel) {
            $taskModel = $task->toModel();
        }

        // Set the task model on the task
        $task->setTaskModel($taskModel);

        // Update task status to running
        $taskModel->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
        ]);

        try {
            // Create pending task
            $pendingTask = $task->pending();

            // Set timeout if specified
            if ($task->getTimeout()) {
                $pendingTask->timeout($task->getTimeout());
            }

            // Set connection if task has a server
            if ($taskModel->server) {
                $connection = $taskModel->user === 'root'
                    ? $taskModel->server->connectionAsRoot()
                    : $taskModel->server->connectionAsUser();

                $pendingTask->onConnection($connection);
            }

            // Dispatch task started event
            event(new TaskStarted($task, $pendingTask, [
                'task_model_id' => $taskModel->id,
                'server_id' => $taskModel->server_id,
                'user' => $taskModel->user,
            ]));

            // Run the task
            $output = $this->run($pendingTask);

            // Update task model with results
            $taskModel->update([
                'status' => $output->isSuccessful()
                    ? TaskStatus::Finished
                    : TaskStatus::Failed,
                'exit_code' => $output->getExitCode(),
                'output' => $output->getBuffer(),
                'completed_at' => now(),
            ]);

            // Update task
            $task->setStatus($taskModel->status);
            $task->setExitCode($output->getExitCode());
            $task->setOutput($output->getBuffer());

            // Dispatch task completed event
            event(new TaskCompleted($task, $pendingTask, $output, now()->toISOString(), [
                'task_model_id' => $taskModel->id,
                'server_id' => $taskModel->server_id,
                'user' => $taskModel->user,
            ]));

            // Handle callbacks if task implements HasCallbacks
            if ($task instanceof HasCallbacks) {
                $callbackType = $output->isSuccessful()
                    ? CallbackType::Finished
                    : CallbackType::Failed;

                // Create a mock request for the callback
                $request = request();
                $task->handleCallback($taskModel, $request, $callbackType);
            }

            return $output;

        } catch (Throwable $e) {
            // Update task model with failure
            $taskModel->update([
                'status' => TaskStatus::Failed,
                'exit_code' => null,
                'output' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Update task
            $task->setStatus(TaskStatus::Failed);
            $task->setOutput($e->getMessage());

            // Dispatch task failed event
            event(new TaskFailed($task, $pendingTask ?? null, null, $e, now()->toISOString(), 'Task execution failed', [
                'task_model_id' => $taskModel->id,
                'server_id' => $taskModel->server_id,
                'user' => $taskModel->user,
            ]));

            // Handle failure callback if task implements HasCallbacks
            if ($task instanceof HasCallbacks) {
                $request = request();
                $task->handleCallback($taskModel, $request, CallbackType::Failed);
            }

            throw new TaskExecutionException(
                'Task execution failed: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Run a task in the background with model support.
     */
    public function runInBackgroundWithModel(Task $task, ?TaskModel $taskModel = null): ProcessOutput
    {
        // Create or use existing task model
        if (! $taskModel) {
            $taskModel = $task->toModel();
            $taskModel->save(); // Save the model to the database
        }

        // Set the task model on the task
        $task->setTaskModel($taskModel);

        // Update task status to running
        $taskModel->update([
            'status' => TaskStatus::Running,
            'started_at' => now(),
        ]);

        // For local tasks (no server), dispatch to queue instead of running as background shell process
        if (! $taskModel->server) {
            return $this->dispatchLocalTaskToQueue($task, $taskModel);
        }

        // For remote tasks, use the original background shell process approach
        return $this->runRemoteTaskInBackground($task, $taskModel);
    }

    /**
     * Dispatch local tasks to Laravel's queue system.
     */
    private function dispatchLocalTaskToQueue(Task $task, TaskModel $taskModel): ProcessOutput
    {
        Log::info('Dispatching local task to queue', [
            'task_id' => $taskModel->id,
            'task_name' => $taskModel->name,
            'task_class' => get_class($task),
        ]);

        // Dispatch the task to the queue
        ExecuteTaskJob::dispatch(
            $taskModel,
            get_class($task),
            $this->extractTaskData($task)
        );

        // Return a mock ProcessOutput since the task will be executed by the queue worker
        return new ProcessOutput(
            buffer: 'Task dispatched to queue',
            exitCode: 0,
            timeout: false
        );
    }

    /**
     * Run remote tasks using the original background shell process approach.
     */
    private function runRemoteTaskInBackground(Task $task, TaskModel $taskModel): ProcessOutput
    {
        // Create pending task
        $pendingTask = $task->pending()->inBackground();

        // Set timeout if specified
        if ($task->getTimeout()) {
            $pendingTask->timeout($task->getTimeout());
        }

        // Set connection for remote tasks
        $connection = $taskModel->user === 'root'
            ? $taskModel->server->connectionAsRoot()
            : $taskModel->server->connectionAsUser();

        $pendingTask->onConnection($connection);

        // Dispatch task started event
        event(new TaskStarted($task, $pendingTask, [
            'task_model_id' => $taskModel->id,
            'server_id' => $taskModel->server_id,
            'user' => $taskModel->user,
            'background' => true,
        ]));

        // Run the task in background
        $output = $this->runInBackground($pendingTask);

        // Start background output monitoring
        $this->startBackgroundMonitoring($task, $taskModel);

        return $output;
    }

    /**
     * Extract task data for serialization.
     */
    private function extractTaskData(Task $task): array
    {
        $data = [];
        $reflection = new \ReflectionClass($task);

        // Extract constructor parameters for proper task reconstruction
        $constructor = $reflection->getConstructor();
        if ($constructor) {
            $parameters = $constructor->getParameters();
            foreach ($parameters as $parameter) {
                $paramName = $parameter->getName();

                // Check if the parameter has a corresponding property
                if (property_exists($task, $paramName)) {
                    $value = $task->$paramName;

                    // Only include serializable values
                    if (is_scalar($value) || is_array($value) || is_null($value)) {
                        $data[$paramName] = $value;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Wrap a task with TrackTaskInBackground to enable callbacks.
     */
    protected function wrapWithTrackTaskInBackground(Task $task, TaskModel $taskModel): TrackTaskInBackground
    {
        // Generate webhook URLs for the task
        $finishedUrl = $taskModel->webhookUrl('markAsFinished');
        $failedUrl = $taskModel->webhookUrl('markAsFailed');
        $timeoutUrl = $taskModel->webhookUrl('markAsTimedOut');

        // Create TrackTaskInBackground wrapper
        $trackTask = new TrackTaskInBackground(
            $task,
            $finishedUrl,
            $failedUrl,
            $timeoutUrl
        );

        // Set the task model on the wrapper
        $trackTask->setTaskModel($taskModel);

        return $trackTask;
    }

    /**
     * Start background monitoring for a task.
     */
    protected function startBackgroundMonitoring(Task $task, TaskModel $taskModel): void
    {
        // Dispatch a job to monitor the task output (every 15 seconds)
        UpdateTaskOutput::dispatch($taskModel, 15)
            ->onQueue('task-output');

        // Set up a timeout job if the task has a timeout
        if ($task->getTimeout()) {
            TaskTimeoutJob::dispatch($taskModel)
                ->delay(now()->addSeconds($task->getTimeout()));
        }
    }

    /**
     * Create a task from a model.
     */
    public function createFromModel(TaskModel $taskModel): ?Task
    {
        if (! $taskModel->instance) {
            return null;
        }

        try {
            $instance = unserialize($taskModel->instance);

            if ($instance instanceof Task) {
                return $instance::fromModel($taskModel);
            }
        } catch (Throwable $e) {
            Log::error('Failed to create task from model', [
                'task_model_id' => $taskModel->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Run multiple tasks in parallel with model support.
     */
    public function runParallelWithModels(array $tasks): array
    {
        $results = [];
        $promises = [];

        foreach ($tasks as $index => $task) {
            if ($task instanceof Task) {
                $promises[$index] = function () use ($task) {
                    return $this->runWithModel($task);
                };
            }
        }

        // For now, run sequentially but track as parallel
        // In a real implementation, you'd use async/await or parallel processing
        foreach ($promises as $index => $promise) {
            try {
                $results[$index] = $promise();
            } catch (Throwable $e) {
                $results[$index] = $e;
            }
        }

        return $results;
    }

    /**
     * Run tasks in a chain with model support.
     */
    public function runChainWithModels(array $tasks, array $options = []): array
    {
        $chain = $this->chain();

        foreach ($tasks as $task) {
            if ($task instanceof Task) {
                $chain->add($task);
            }
        }

        if (! empty($options)) {
            $chain->withOptions($options);
        }

        return $chain->run();
    }

    /**
     * Get task statistics.
     */
    public function getTaskStatistics(): array
    {
        $totalTasks = TaskModel::count();
        $pendingTasks = TaskModel::where('status', TaskStatus::Pending)->count();
        $runningTasks = TaskModel::where('status', TaskStatus::Running)->count();
        $finishedTasks = TaskModel::where('status', TaskStatus::Finished)->count();
        $failedTasks = TaskModel::whereIn('status', TaskStatus::getFailedStatuses())->count();

        return [
            'total' => $totalTasks,
            'pending' => $pendingTasks,
            'running' => $runningTasks,
            'finished' => $finishedTasks,
            'failed' => $failedTasks,
            'success_rate' => $totalTasks > 0 ? ($finishedTasks / $totalTasks) * 100 : 0,
        ];
    }

    /**
     * Clean up old completed tasks.
     */
    public function cleanupOldTasks(int $daysOld = 30): int
    {
        $cutoffDate = now()->subDays($daysOld);

        return TaskModel::whereIn('status', TaskStatus::getCompletedStatuses())
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    protected function handleFakeTask(PendingTask $pendingTask, FakeTask $fakeTask): ProcessOutput
    {
        $this->dispatchedTasks[] = $pendingTask;
        $this->storePersistentFake();

        return $fakeTask instanceof FakeTask
            ? $fakeTask->processOutput
            : new ProcessOutput;
    }

    protected function runLocally(PendingTask $pendingTask): ProcessOutput
    {
        $command = $pendingTask->storeInTemporaryDirectory();
        $timeout = $pendingTask->task->getTimeout() ?? $this->defaultTimeout ?? self::DEFAULT_TIMEOUT;

        return $this->processRunner->run(
            FacadesProcess::command($command)->timeout($timeout)
        );
    }

    public function runInBackground(PendingTask $pendingTask): ProcessOutput
    {
        // For local tasks without output path, wrap with database logging
        if (! $pendingTask->getOutputPath()) {
            return $this->runLocalBackgroundWithDatabaseLogging($pendingTask);
        }

        // For TrackTaskInBackground tasks, use the template instead of direct script execution
        if ($pendingTask->task instanceof TrackTaskInBackground) {
            return $this->runTrackTaskInBackground($pendingTask);
        }

        $command = Helper::scriptInBackground(
            scriptPath: $pendingTask->storeInTemporaryDirectory(),
            outputPath: $pendingTask->getOutputPath(),
            timeout: $pendingTask->task->getTimeout() ?? $this->defaultTimeout ?? self::DEFAULT_TIMEOUT
        );

        return $this->processRunner->run(
            FacadesProcess::command($command)->timeout(self::DEFAULT_TIMEOUT)
        );
    }

    /**
     * Run a TrackTaskInBackground task using the template.
     */
    private function runTrackTaskInBackground(PendingTask $pendingTask): ProcessOutput
    {
        /** @var TrackTaskInBackground $task */
        $task = $pendingTask->task;

        try {
            // Generate the script using the template
            $scriptPath = $pendingTask->storeInTemporaryDirectory();
            $scriptContent = $task->getScript();

            // Write the template-generated script to the file
            file_put_contents($scriptPath, $scriptContent);
            chmod($scriptPath, 0755);

            // Use the template's output path
            $outputPath = $pendingTask->getOutputPath();
            $timeout = $task->getTimeout() ?? $this->defaultTimeout ?? self::DEFAULT_TIMEOUT;

            $command = Helper::scriptInBackground(
                scriptPath: $scriptPath,
                outputPath: $outputPath,
                timeout: $timeout
            );

            return $this->processRunner->run(
                FacadesProcess::command($command)->timeout(self::DEFAULT_TIMEOUT)
            );
        } catch (\Exception $e) {
            Log::error('Failed to run TrackTaskInBackground task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Run a local background task and write output directly to database.
     */
    private function runLocalBackgroundWithDatabaseLogging(PendingTask $pendingTask): ProcessOutput
    {
        $task = $pendingTask->task;
        $taskModel = $task->getTaskModel();

        if (! $taskModel) {
            throw new \Exception('Task model not found for local background task');
        }

        // Create a wrapper script that captures output and writes to database
        $scriptPath = $pendingTask->storeInTemporaryDirectory();
        $originalScript = $scriptPath.'.original';

        // Move original script to .original
        rename($scriptPath, $originalScript);

        // Create wrapper script
        $wrapperScript = $this->generateDatabaseOutputWrapper($originalScript, $taskModel->id);
        file_put_contents($scriptPath, $wrapperScript);
        chmod($scriptPath, 0755);

        // Run the script in background without output redirection
        $timeout = $task->getTimeout() ?? $this->defaultTimeout ?? self::DEFAULT_TIMEOUT;
        $command = $timeout > 0
            ? "timeout {$timeout}s bash {$scriptPath} &"
            : "bash {$scriptPath} &";

        return $this->processRunner->run(
            FacadesProcess::command($command)->timeout(self::DEFAULT_TIMEOUT)
        );
    }

    /**
     * Generate a script that writes output directly to the database.
     */
    private function generateDatabaseOutputWrapper(string $originalScript, string $taskId): string
    {
        $eofMarker = Helper::eof();

        return <<<SCRIPT
#!/bin/bash

# Function to append output to database
append_to_database() {
    local output="\$1"
    if [ -n "\$output" ]; then
        php artisan task:update-output {$taskId} "\$output" > /dev/null 2>&1
    fi
}

# Execute the original script and capture its output
bash "{$originalScript}" 2>&1 | while IFS= read -r line; do
    echo "\$line"
    append_to_database "\$line"
done

# Add completion marker
echo "{$eofMarker}"
append_to_database "{$eofMarker}"

# Mark task as completed
php artisan task:complete {$taskId} --exit-code=0 > /dev/null 2>&1
SCRIPT;
    }

    public function runOnConnection(PendingTask $pendingTask): ProcessOutput
    {
        $runner = $this->createRemoteRunner($pendingTask);
        $id = $pendingTask->getId() ?: Str::random(32);
        $scriptFile = $id.self::SCRIPT_EXTENSION;
        $logFile = $id.self::LOG_EXTENSION;
        $timeout = $pendingTask->task->getTimeout() ?? $this->defaultTimeout ?? 0;

        $runner->verifyScriptDirectoryExists()
            ->upload($scriptFile, $pendingTask->task->getScript());

        return $pendingTask->shouldRunInBackground()
            ? $runner->runUploadedScriptInBackground($scriptFile, $logFile, $timeout)
            : $runner->runUploadedScript($scriptFile, $logFile, $timeout);
    }

    protected function createRemoteRunner(PendingTask $pendingTask): RemoteProcessRunner
    {
        /** @var RemoteProcessRunner $runner */
        $runner = app()->makeWith(RemoteProcessRunner::class, [
            'connection' => $pendingTask->getConnection(),
            'processRunner' => $this->processRunner,
        ]);

        if ($outputCallback = $pendingTask->getOnOutput()) {
            $runner->onOutput($outputCallback);
        }

        return $runner;
    }

    /**
     * @param  array<int, string>|string  $tasksToFake
     */
    public function fake(array|string $tasksToFake = []): self
    {
        $this->tasksToFake = Collection::wrap($tasksToFake)
            ->map(fn ($value, $key) => $this->createFakeTask($value, $key))
            ->filter()
            ->values()
            ->all();

        $this->storePersistentFake();

        return $this;
    }

    /**
     * Disable fake mode and reset faking state.
     */
    public function unfake(): self
    {
        $this->tasksToFake = false;
        $this->tasksToDispatch = [];
        $this->preventStrayTasks = false;
        $this->storePersistentFake();

        return $this;
    }

    protected function createFakeTask(mixed $value, mixed $key): ?FakeTask
    {
        if (is_string($key) && is_string($value)) {
            return new FakeTask($key, ProcessOutput::make($value)->setExitCode(0));
        }

        if (is_string($value)) {
            return new FakeTask($value, ProcessOutput::make()->setExitCode(0));
        }

        if (is_string($key) && $value instanceof ProcessOutput) {
            return new FakeTask($key, $value);
        }

        return null;
    }

    /**
     * Don't fake specific tasks.
     *
     * @param  array<int, string>|string  $taskToDispatch
     */
    public function dontFake(array|string $taskToDispatch): self
    {
        $this->tasksToDispatch = array_merge($this->tasksToDispatch, Arr::wrap($taskToDispatch));

        $this->storePersistentFake();

        return $this;
    }

    /**
     * Prevents stray tasks from being executed.
     */
    public function preventStrayTasks(bool $prevent = true): self
    {
        $this->preventStrayTasks = $prevent;

        return $this;
    }

    /**
     * Returns a boolean if the task should be faked or the corresponding fake task.
     */
    public function taskShouldBeFaked(PendingTask $pendingTask): bool|FakeTask
    {
        foreach ($this->tasksToDispatch as $dontFake) {
            if ($pendingTask->task instanceof $dontFake) {
                return false;
            }
        }

        if ($this->tasksToFake === []) {
            return new FakeTask(get_class($pendingTask->task), ProcessOutput::make()->setExitCode(0));
        }

        if ($this->tasksToFake === false && ! config('task-runner.persistent_fake.enabled')) {
            return false;
        }

        $fakeTask = collect($this->tasksToFake ?: [])->first(function (FakeTask $fakeTask) use ($pendingTask) {
            return $pendingTask->task instanceof $fakeTask->taskClass;
        });

        if (! $fakeTask && $this->preventStrayTasks) {
            throw new RuntimeException('Attempted dispatch task ['.get_class($pendingTask->task).'] without a matching fake.');
        }

        return $fakeTask ?: false;
    }

    /**
     * Returns the dispatched tasks, filtered by a callback.
     *
     * @return Collection<int, FakeTask>
     */
    protected function faked(callable $callback): Collection
    {
        $this->loadPersistentFake();

        return collect($this->dispatchedTasks)
            ->filter(function (PendingTask $pendingTask) use ($callback) {
                $refFunction = new \ReflectionFunction($callback);

                $parameters = $refFunction->getParameters();

                if ($parameters[0]->getType()) {
                    $typeHint = (string) $parameters[0]->getType();
                    if (! $typeHint || $typeHint === PendingTask::class) {
                        return $callback($pendingTask);
                    }
                }

                return $callback($pendingTask->task);
            })
            ->values();
    }

    /**
     * Run an anonymous task.
     */
    public function runAnonymous(AnonymousTask $task): ?ProcessOutput
    {
        return $this->run($task->pending());
    }

    /**
     * Create an anonymous task with a script.
     */
    public function anonymous(string $name, string $script, array $options = []): AnonymousTask
    {
        return AnonymousTask::script($name, $script, $options);
    }

    /**
     * Create an anonymous task for a simple command.
     */
    public function command(string $name, string $command, array $options = []): AnonymousTask
    {
        return AnonymousTask::command($name, $command, $options);
    }

    /**
     * Create an anonymous task for multiple commands.
     */
    public function commands(string $name, array $commands, array $options = []): AnonymousTask
    {
        return AnonymousTask::commands($name, $commands, $options);
    }

    /**
     * Create an anonymous task with a view.
     */
    public function view(string $name, string $view, array $data = [], array $options = []): AnonymousTask
    {
        return AnonymousTask::view($name, $view, $data, $options);
    }

    /**
     * Create an anonymous task with a render callback.
     */
    public function callback(string $name, \Closure $callback, array $options = []): AnonymousTask
    {
        return AnonymousTask::callback($name, $callback, $options);
    }

    /**
     * Dispatch a task for execution.
     */
    public function dispatch(string $command, array $arguments = []): ProcessOutput
    {
        $task = AnonymousTask::command('Dispatched Task', $command);

        foreach ($arguments as $key => $value) {
            $task->setData($key, $value);
        }

        return $this->run($task->pending()) ?? new ProcessOutput;
    }

    /**
     * Dispatch a task to multiple servers.
     */
    public function dispatchToMultipleServers(Task $task, array $connections, array $options = []): array
    {
        $multiServerDispatcher = new MultiServerDispatcher($this);

        return $multiServerDispatcher->dispatch($task, $connections, $options);
    }

    /**
     * Dispatch a task to multiple servers using various connection sources.
     */
    public function dispatchToMultipleConnections(Task $task, mixed $connectionSources, array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createConnections($connectionSources);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from database query.
     */
    public function dispatchToDatabaseServers(Task $task, string $table, array $where = [], array $orderBy = [], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromQuery($table, $where, $orderBy);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from model query.
     */
    public function dispatchToModelServers(Task $task, string $modelClass, array $where = [], array $orderBy = [], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromModelQuery($modelClass, $where, $orderBy);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers by group.
     */
    public function dispatchToGroup(Task $task, string $groupName, string $table = 'servers', array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromGroup($groupName, $table);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers by tags.
     */
    public function dispatchToTaggedServers(Task $task, array $tags, string $table = 'servers', array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromTags($tags, $table);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from environment variables.
     */
    public function dispatchToEnvironmentServers(Task $task, array $prefixes = ['SSH_'], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromEnvironment($prefixes);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from JSON file.
     */
    public function dispatchToJsonFileServers(Task $task, string $filePath, array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromJsonFile($filePath);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from CSV file.
     */
    public function dispatchToCsvFileServers(Task $task, string $filePath, array $columnMapping = [], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromCsvFile($filePath, $columnMapping);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Run multiple tasks in parallel.
     */
    public function runParallel(array $tasks, array $options = []): array
    {
        $parallelExecutor = ParallelTaskExecutor::make($this);

        foreach ($tasks as $task) {
            $parallelExecutor->add($task);
        }

        $parallelExecutor->withOptions($options);

        return $parallelExecutor->run();
    }

    /**
     * Run a task chain in parallel.
     */
    public function runChainParallel(TaskChain $chain): array
    {
        return $chain->withParallel(true)->run();
    }

    /**
     * Run multiple task chains in parallel.
     */
    public function runChainsParallel(array $chains, array $options = []): array
    {
        $parallelExecutor = ParallelTaskExecutor::make($this);

        foreach ($chains as $chain) {
            // Convert each chain to a single task that runs the chain
            $chainTask = AnonymousTask::callback(
                "Chain: {$chain->getChainId()}",
                function () use ($chain) {
                    return $chain->run();
                }
            );
            $parallelExecutor->add($chainTask);
        }

        $parallelExecutor->withOptions($options);

        return $parallelExecutor->run();
    }

    /**
     * Dispatch an anonymous task to multiple servers.
     */
    public function dispatchAnonymousToMultipleServers(AnonymousTask $task, array $connections, array $options = []): array
    {
        return $this->dispatchToMultipleServers($task, $connections, $options);
    }

    /**
     * Create a new task chain.
     */
    public function chain(): TaskChain
    {
        return new TaskChain($this);
    }

    /**
     * Run a task chain.
     */
    public function runChain(TaskChain $chain): array
    {
        return $chain->run();
    }

    /**
     * Create and run a task chain with the given tasks.
     */
    public function runTaskChain(array $tasks, array $options = []): array
    {
        $chain = $this->chain();

        foreach ($tasks as $task) {
            $chain->add($task);
        }

        if (! empty($options)) {
            $chain->withOptions($options);
        }

        return $chain->run();
    }
}
