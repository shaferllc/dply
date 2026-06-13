<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Events\TaskCompleted;
use App\Modules\TaskRunner\Events\TaskFailed;
use App\Modules\TaskRunner\Events\TaskStarted;
use App\Modules\TaskRunner\Exceptions\TaskExecutionException;
use App\Modules\TaskRunner\FakeTask;
use App\Modules\TaskRunner\Helper;
use App\Modules\TaskRunner\Jobs\ExecuteTaskJob;
use App\Modules\TaskRunner\Jobs\TaskTimeoutJob;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\RemoteProcessRunner;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait RunsTaskRunnerTasks
{


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
        $pendingTask->withId('task-'.$taskModel->id);

        // Set timeout if specified
        if ($task->getTimeout()) {
            $pendingTask->timeout($task->getTimeout());
        }

        // Set connection for remote tasks
        $connection = $taskModel->user === 'root'
            ? $taskModel->server->connectionAsRoot()
            : $taskModel->server->connectionAsUser();

        $pendingTask->onConnection($connection);

        $options = is_array($taskModel->options) ? $taskModel->options : [];
        if ($task instanceof TrackTaskInBackground) {
            $wrapperScriptPath = $connection->scriptPath.'/task-'.$taskModel->id.self::SCRIPT_EXTENSION;
            $actualScriptPath = $connection->scriptPath.'/task-'.$taskModel->id.'-original'.self::SCRIPT_EXTENSION;
            $options['remote_wrapper_script_path'] = $wrapperScriptPath;
            $options['remote_script_path'] = $actualScriptPath;
            $options['remote_output_path'] = $actualScriptPath.self::LOG_EXTENSION;
            $options['remote_pid_path'] = $wrapperScriptPath.'.pid';
            $options['remote_child_pid_path'] = $actualScriptPath.'.pid';
        } else {
            $options['remote_script_path'] = $connection->scriptPath.'/task-'.$taskModel->id.self::SCRIPT_EXTENSION;
            $options['remote_output_path'] = $connection->scriptPath.'/task-'.$taskModel->id.self::LOG_EXTENSION;
            $options['remote_pid_path'] = $options['remote_script_path'].'.pid';
        }
        $taskModel->update(['options' => $options]);

        // Dispatch task started event
        event(new TaskStarted($task, $pendingTask, [
            'task_model_id' => $taskModel->id,
            'server_id' => $taskModel->server_id,
            'user' => $taskModel->user,
            'background' => true,
        ]));

        // Run the task in background on the configured remote connection
        $output = $this->runOnConnection($pendingTask);

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
     * Wrap a task with TrackTaskInBackground so remote execution uses shell HTTP callbacks
     * (update-output, mark-as-finished, etc.).
     */
    public function wrapWithTrackTaskInBackground(Task $task, TaskModel $taskModel): TrackTaskInBackground
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
        if ($task instanceof TrackTaskInBackground) {
            return;
        }

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
        $remoteScriptPath = $runner->path($scriptFile);
        $remoteOutputPath = $runner->path($logFile);

        if ($taskModel = $pendingTask->task->getTaskModel()) {
            $options = is_array($taskModel->options) ? $taskModel->options : [];
            $options['remote_script_path'] = $remoteScriptPath;
            $options['remote_output_path'] = $remoteOutputPath;
            $taskModel->update(['options' => $options]);
        }

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
}
