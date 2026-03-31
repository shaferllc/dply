<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Events\ParallelTaskCompleted;
use App\Modules\TaskRunner\Events\ParallelTaskFailed;
use App\Modules\TaskRunner\Events\ParallelTaskProgress;
use App\Modules\TaskRunner\Events\ParallelTaskStarted;
use App\Modules\TaskRunner\Exceptions\ParallelTaskException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Parallel Task Executor for running multiple tasks concurrently.
 */
class ParallelTaskExecutor
{
    /**
     * The task dispatcher.
     */
    protected TaskDispatcher $dispatcher;

    /**
     * The streaming logger.
     */
    protected ?StreamingLoggerInterface $streamingLogger;

    /**
     * The execution ID.
     */
    protected string $executionId;

    /**
     * The tasks to execute.
     */
    protected Collection $tasks;

    /**
     * The execution options.
     */
    protected array $options;

    /**
     * The execution results.
     */
    protected array $results = [];

    /**
     * The start timestamp.
     */
    protected string $startedAt;

    /**
     * The maximum concurrent tasks.
     */
    protected int $maxConcurrency;

    /**
     * Create a new ParallelTaskExecutor instance.
     */
    public function __construct(TaskDispatcher $dispatcher, ?StreamingLoggerInterface $streamingLogger = null)
    {
        $this->dispatcher = $dispatcher;
        $this->streamingLogger = $streamingLogger;
        $this->executionId = uniqid('parallel_', true);
        $this->tasks = collect();
        $this->options = [
            'max_concurrency' => 5,
            'timeout' => null,
            'streaming' => true,
            'progress_tracking' => true,
            'stop_on_failure' => false,
            'min_success' => null,
            'max_failures' => null,
        ];
    }

    /**
     * Create a new ParallelTaskExecutor instance.
     */
    public static function make(?TaskDispatcher $dispatcher = null, ?StreamingLoggerInterface $streamingLogger = null): self
    {
        if ($dispatcher === null) {
            $dispatcher = app(TaskDispatcher::class);
        }

        return new self($dispatcher, $streamingLogger);
    }

    /**
     * Add a task to execute.
     */
    public function add(Task $task): self
    {
        $this->tasks->push($task);

        return $this;
    }

    /**
     * Add multiple tasks to execute.
     */
    public function addMany(array $tasks): self
    {
        foreach ($tasks as $task) {
            $this->add($task);
        }

        return $this;
    }

    /**
     * Add an anonymous task to execute.
     */
    public function addAnonymous(AnonymousTask $task): self
    {
        return $this->add($task);
    }

    /**
     * Add a command task to execute.
     */
    public function addCommand(string $name, string $command, array $options = []): self
    {
        $task = AnonymousTask::command($name, $command, $options);

        return $this->add($task);
    }

    /**
     * Add multiple commands to execute.
     */
    public function addCommands(string $name, array $commands, array $options = []): self
    {
        $task = AnonymousTask::commands($name, $commands, $options);

        return $this->add($task);
    }

    /**
     * Add a view task to execute.
     */
    public function addView(string $name, string $view, array $data = [], array $options = []): self
    {
        $task = AnonymousTask::view($name, $view, $data, $options);

        return $this->add($task);
    }

    /**
     * Add a callback task to execute.
     */
    public function addCallback(string $name, \Closure $callback, array $options = []): self
    {
        $task = AnonymousTask::callback($name, $callback, $options);

        return $this->add($task);
    }

    /**
     * Set execution options.
     */
    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * Set maximum concurrency.
     */
    public function withMaxConcurrency(int $maxConcurrency): self
    {
        $this->options['max_concurrency'] = max(1, $maxConcurrency);

        return $this;
    }

    /**
     * Set timeout for the entire execution.
     */
    public function withTimeout(?int $timeout): self
    {
        $this->options['timeout'] = $timeout;

        return $this;
    }

    /**
     * Enable or disable streaming.
     */
    public function withStreaming(bool $enabled = true): self
    {
        $this->options['streaming'] = $enabled;

        return $this;
    }

    /**
     * Enable or disable progress tracking.
     */
    public function withProgressTracking(bool $enabled = true): self
    {
        $this->options['progress_tracking'] = $enabled;

        return $this;
    }

    /**
     * Set stop on failure behavior.
     */
    public function stopOnFailure(bool $stop = true): self
    {
        $this->options['stop_on_failure'] = $stop;

        return $this;
    }

    /**
     * Set minimum success requirement.
     */
    public function withMinSuccess(?int $minSuccess): self
    {
        $this->options['min_success'] = $minSuccess;

        return $this;
    }

    /**
     * Set maximum failures allowed.
     */
    public function withMaxFailures(?int $maxFailures): self
    {
        $this->options['max_failures'] = $maxFailures;

        return $this;
    }

    /**
     * Set streaming logger.
     */
    public function withStreamingLogger(?StreamingLoggerInterface $streamingLogger): self
    {
        $this->streamingLogger = $streamingLogger;

        return $this;
    }

    /**
     * Execute all tasks in parallel.
     */
    public function run(): array
    {
        $this->startedAt = now()->toISOString();
        $this->results = [];
        $this->maxConcurrency = $this->options['max_concurrency'];

        $totalTasks = $this->tasks->count();
        if ($totalTasks === 0) {
            throw new ParallelTaskException('Cannot run empty parallel task execution');
        }

        // Dispatch parallel execution started event
        event(new ParallelTaskStarted(
            $this->tasks->toArray(),
            $this->executionId,
            $this->startedAt,
            $this->options
        ));

        // Stream execution start
        $this->streamExecutionEvent('started', [
            'execution_id' => $this->executionId,
            'total_tasks' => $totalTasks,
            'max_concurrency' => $this->maxConcurrency,
            'options' => $this->options,
        ]);

        try {
            if ($this->maxConcurrency === 1) {
                return $this->runSequentially();
            } else {
                return $this->runInParallel();
            }
        } catch (Throwable $e) {
            $this->handleExecutionFailure($e);
            throw $e;
        }
    }

    /**
     * Run tasks sequentially (when max_concurrency = 1).
     */
    protected function runSequentially(): array
    {
        $totalTasks = $this->tasks->count();

        foreach ($this->tasks as $index => $task) {
            $this->executeTask($task, $index, $totalTasks);

            // Check if we should stop on failure
            if ($this->options['stop_on_failure'] && ! $this->results[$index]['success']) {
                break;
            }
        }

        return $this->processExecutionResults();
    }

    /**
     * Run tasks in parallel with concurrency control.
     */
    protected function runInParallel(): array
    {
        $totalTasks = $this->tasks->count();
        $completedTasks = 0;
        $runningTasks = [];
        $taskPromises = [];

        // Create promises for all tasks
        foreach ($this->tasks as $index => $task) {
            $taskPromises[$index] = $this->createTaskPromise($task, $index, $totalTasks);
        }

        // Execute tasks with concurrency control
        while ($completedTasks < $totalTasks) {
            // Start new tasks up to max concurrency
            while (count($runningTasks) < $this->maxConcurrency && ! empty($taskPromises)) {
                $taskIndex = array_key_first($taskPromises);
                $promise = $taskPromises[$taskIndex];
                unset($taskPromises[$taskIndex]);

                $runningTasks[$taskIndex] = $promise;
            }

            // Wait for at least one task to complete
            if (! empty($runningTasks)) {
                foreach ($runningTasks as $taskIndex => $promise) {
                    try {
                        $result = $promise();
                        $this->results[$taskIndex] = $result;
                        $completedTasks++;

                        // Update progress
                        if ($this->options['progress_tracking']) {
                            $this->updateProgress($completedTasks, $totalTasks, "Completed: {$result['task_name']}");
                        }

                        // Check if we should stop on failure
                        if ($this->options['stop_on_failure'] && ! $result['success']) {
                            // Cancel remaining tasks
                            $taskPromises = [];
                            break 2;
                        }

                        unset($runningTasks[$taskIndex]);

                    } catch (Throwable $e) {
                        $this->results[$taskIndex] = [
                            'task_name' => $this->tasks[$taskIndex]->getName(),
                            'task_class' => get_class($this->tasks[$taskIndex]),
                            'success' => false,
                            'exit_code' => null,
                            'output' => '',
                            'error' => $e->getMessage(),
                            'exception' => $e,
                            'duration' => 0,
                            'completed_at' => now()->toISOString(),
                        ];

                        $completedTasks++;

                        // Stream task failure
                        $this->streamExecutionEvent('task_failed', [
                            'execution_id' => $this->executionId,
                            'task_index' => $taskIndex,
                            'task_name' => $this->results[$taskIndex]['task_name'],
                            'error' => $e->getMessage(),
                        ]);

                        unset($runningTasks[$taskIndex]);

                        // Check if we should stop on failure
                        if ($this->options['stop_on_failure']) {
                            // Cancel remaining tasks
                            $taskPromises = [];
                            break 2;
                        }
                    }
                }
            }

            // Small delay to prevent busy waiting
            if (! empty($runningTasks)) {
                usleep(10000); // 10ms
            }
        }

        return $this->processExecutionResults();
    }

    /**
     * Create a promise for task execution.
     */
    protected function createTaskPromise(Task $task, int $index, int $totalTasks): callable
    {
        return function () use ($task, $index, $totalTasks) {
            return $this->executeTask($task, $index, $totalTasks);
        };
    }

    /**
     * Execute a single task.
     */
    protected function executeTask(Task $task, int $index, int $totalTasks): array
    {
        $taskName = $task->getName();
        $taskClass = get_class($task);
        $startTime = microtime(true);

        // Stream task start
        $this->streamExecutionEvent('task_started', [
            'execution_id' => $this->executionId,
            'task_index' => $index,
            'task_name' => $taskName,
            'task_class' => $taskClass,
            'total_tasks' => $totalTasks,
            'current_task' => $index + 1,
        ]);

        try {
            // Execute the task
            $pendingTask = $task->pending();

            // Set timeout if specified
            if ($this->options['timeout']) {
                $pendingTask->timeout($this->options['timeout']);
            }

            $result = $this->dispatcher->run($pendingTask);
            $duration = microtime(true) - $startTime;

            $taskResult = [
                'task_name' => $taskName,
                'task_class' => $taskClass,
                'success' => $result ? $result->isSuccessful() : false,
                'exit_code' => $result ? $result->getExitCode() : null,
                'output' => $result ? $result->getBuffer() : '',
                'duration' => $duration,
                'completed_at' => now()->toISOString(),
            ];

            // Stream task completion
            $this->streamExecutionEvent('task_completed', [
                'execution_id' => $this->executionId,
                'task_index' => $index,
                'task_name' => $taskName,
                'success' => $taskResult['success'],
                'exit_code' => $taskResult['exit_code'],
                'duration' => $taskResult['duration'],
            ]);

            return $taskResult;

        } catch (Throwable $e) {
            $duration = microtime(true) - $startTime;

            $taskResult = [
                'task_name' => $taskName,
                'task_class' => $taskClass,
                'success' => false,
                'exit_code' => null,
                'output' => '',
                'error' => $e->getMessage(),
                'exception' => $e,
                'duration' => $duration,
                'completed_at' => now()->toISOString(),
            ];

            // Stream task failure
            $this->streamExecutionEvent('task_failed', [
                'execution_id' => $this->executionId,
                'task_index' => $index,
                'task_name' => $taskName,
                'error' => $e->getMessage(),
                'duration' => $duration,
            ]);

            return $taskResult;
        }
    }

    /**
     * Process the execution results.
     */
    protected function processExecutionResults(): array
    {
        $totalTasks = $this->tasks->count();
        $completedTasks = count($this->results);
        $successfulTasks = collect($this->results)->where('success', true)->count();
        $failedTasks = $completedTasks - $successfulTasks;

        $summary = [
            'execution_id' => $this->executionId,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'successful_tasks' => $successfulTasks,
            'failed_tasks' => $failedTasks,
            'success_rate' => $completedTasks > 0 ? ($successfulTasks / $completedTasks) * 100 : 0,
            'max_concurrency' => $this->maxConcurrency,
            'started_at' => $this->startedAt,
            'completed_at' => now()->toISOString(),
            'duration' => $this->calculateExecutionDuration(),
            'results' => $this->results,
            'overall_success' => $this->isOverallSuccessful(),
        ];

        // Dispatch completion event
        if ($this->isOverallSuccessful()) {
            event(new ParallelTaskCompleted(
                $this->tasks->toArray(),
                $this->executionId,
                $summary,
                $this->startedAt
            ));
        } else {
            event(new ParallelTaskFailed(
                $this->tasks->toArray(),
                $this->executionId,
                $summary,
                $this->startedAt
            ));
        }

        // Stream execution completion
        $this->streamExecutionEvent('completed', [
            'execution_id' => $this->executionId,
            'success_rate' => $summary['success_rate'],
            'duration' => $summary['duration'],
            'overall_success' => $summary['overall_success'],
        ]);

        return $summary;
    }

    /**
     * Check if the overall execution was successful.
     */
    protected function isOverallSuccessful(): bool
    {
        if (empty($this->results)) {
            return false;
        }

        $successfulTasks = collect($this->results)->where('success', true)->count();
        $totalTasks = count($this->results);

        // Check minimum success requirement
        if ($this->options['min_success'] !== null) {
            return $successfulTasks >= $this->options['min_success'];
        }

        // Check maximum failures requirement
        if ($this->options['max_failures'] !== null) {
            $failedTasks = $totalTasks - $successfulTasks;

            return $failedTasks <= $this->options['max_failures'];
        }

        // Default: all tasks must succeed
        return $successfulTasks === $totalTasks;
    }

    /**
     * Handle execution failure.
     */
    protected function handleExecutionFailure(Throwable $e): void
    {
        $summary = [
            'execution_id' => $this->executionId,
            'total_tasks' => $this->tasks->count(),
            'completed_tasks' => count($this->results),
            'successful_tasks' => collect($this->results)->where('success', true)->count(),
            'failed_tasks' => count($this->results) - collect($this->results)->where('success', true)->count(),
            'started_at' => $this->startedAt,
            'failed_at' => now()->toISOString(),
            'duration' => $this->calculateExecutionDuration(),
            'error' => $e->getMessage(),
            'exception' => $e,
            'results' => $this->results,
            'overall_success' => false,
        ];

        event(new ParallelTaskFailed(
            $this->tasks->toArray(),
            $this->executionId,
            $summary,
            $this->startedAt
        ));

        throw new ParallelTaskException(
            "Parallel task execution failed: {$e->getMessage()}",
            previous: $e
        );
    }

    /**
     * Update progress tracking.
     */
    protected function updateProgress(int $current, int $total, string $message): void
    {
        if (! $this->options['progress_tracking']) {
            return;
        }

        $percentage = $total > 0 ? ($current / $total) * 100 : 0;

        event(new ParallelTaskProgress(
            $this->executionId,
            $current,
            $total,
            $percentage,
            $message,
            now()->toISOString()
        ));

        $this->streamExecutionEvent('progress', [
            'execution_id' => $this->executionId,
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
            'message' => $message,
        ]);
    }

    /**
     * Stream execution event.
     */
    protected function streamExecutionEvent(string $event, array $data = []): void
    {
        if (! $this->options['streaming'] || ! $this->streamingLogger) {
            return;
        }

        $logData = array_merge([
            'event' => $event,
            'execution_id' => $this->executionId,
            'timestamp' => now()->toISOString(),
        ], $data);

        $this->streamingLogger->streamTaskEvent($event, $logData);
    }

    /**
     * Calculate execution duration.
     */
    protected function calculateExecutionDuration(): float
    {
        return now()->diffInSeconds($this->startedAt, true);
    }

    /**
     * Get the execution ID.
     */
    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    /**
     * Get the tasks.
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    /**
     * Get the results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get the options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Check if execution is empty.
     */
    public function isEmpty(): bool
    {
        return $this->tasks->isEmpty();
    }

    /**
     * Get the number of tasks.
     */
    public function count(): int
    {
        return $this->tasks->count();
    }

    /**
     * Clear all tasks.
     */
    public function clear(): self
    {
        $this->tasks = collect();
        $this->results = [];

        return $this;
    }

    /**
     * Get aggregated output from all tasks.
     */
    public function getAggregatedOutput(): string
    {
        return collect($this->results)
            ->pluck('output')
            ->filter()
            ->implode("\n\n---\n\n");
    }

    /**
     * Get aggregated errors from all tasks.
     */
    public function getAggregatedErrors(): string
    {
        return collect($this->results)
            ->where('success', false)
            ->map(function ($result) {
                $error = $result['error'] ?? "Exit code: {$result['exit_code']}";

                return "Task: {$result['task_name']}\nError: {$error}\nOutput: {$result['output']}";
            })
            ->implode("\n\n---\n\n");
    }
}
