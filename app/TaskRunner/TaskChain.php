<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Events\TaskChainCompleted;
use App\Modules\TaskRunner\Events\TaskChainFailed;
use App\Modules\TaskRunner\Events\TaskChainProgress;
use App\Modules\TaskRunner\Events\TaskChainStarted;
use App\Modules\TaskRunner\Exceptions\TaskChainException;
use Illuminate\Support\Collection;
use Throwable;

class TaskChain
{
    /**
     * The tasks in the chain.
     */
    protected Collection $tasks;

    /**
     * The task dispatcher.
     */
    protected TaskDispatcher $dispatcher;

    /**
     * The chain ID.
     */
    protected string $chainId;

    /**
     * The execution options.
     */
    protected array $options;

    /**
     * The streaming logger.
     */
    protected ?Contracts\StreamingLoggerInterface $streamingLogger;

    /**
     * The chain results.
     */
    protected array $results = [];

    /**
     * The current task index.
     */
    protected int $currentTaskIndex = 0;

    /**
     * The start timestamp.
     */
    protected string $startedAt;

    /**
     * Create a new TaskChain instance.
     */
    public function __construct(TaskDispatcher $dispatcher, ?Contracts\StreamingLoggerInterface $streamingLogger = null)
    {
        $this->dispatcher = $dispatcher;
        $this->streamingLogger = $streamingLogger;
        $this->tasks = collect();
        $this->chainId = uniqid('chain_', true);
        $this->options = [
            'stop_on_failure' => true,
            'parallel' => false,
            'timeout' => null,
            'streaming' => true,
            'progress_tracking' => true,
        ];
    }

    /**
     * Create a new TaskChain instance.
     */
    public static function make(?TaskDispatcher $dispatcher = null, ?Contracts\StreamingLoggerInterface $streamingLogger = null): self
    {
        if ($dispatcher === null) {
            $dispatcher = app(TaskDispatcher::class);
        }

        return new self($dispatcher, $streamingLogger);
    }

    /**
     * Add a task to the chain.
     */
    public function add(Task $task): self
    {
        $this->tasks->push($task);

        return $this;
    }

    /**
     * Add multiple tasks to the chain.
     */
    public function addMany(array $tasks): self
    {
        foreach ($tasks as $task) {
            $this->add($task);
        }

        return $this;
    }

    /**
     * Add an anonymous task to the chain.
     */
    public function addAnonymous(AnonymousTask $task): self
    {
        return $this->add($task);
    }

    /**
     * Add a command task to the chain.
     */
    public function addCommand(string $name, string $command, array $options = []): self
    {
        $task = AnonymousTask::command($name, $command, $options);

        return $this->add($task);
    }

    /**
     * Add multiple commands to the chain.
     */
    public function addCommands(string $name, array $commands, array $options = []): self
    {
        $task = AnonymousTask::commands($name, $commands, $options);

        return $this->add($task);
    }

    /**
     * Add a view task to the chain.
     */
    public function addView(string $name, string $view, array $data = [], array $options = []): self
    {
        $task = AnonymousTask::view($name, $view, $data, $options);

        return $this->add($task);
    }

    /**
     * Add a callback task to the chain.
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
     * Set timeout for the entire chain.
     */
    public function withTimeout(?int $timeout): self
    {
        $this->options['timeout'] = $timeout;

        return $this;
    }

    /**
     * Enable parallel execution.
     */
    public function withParallel(bool $enabled = true, int $maxConcurrency = 5): self
    {
        $this->options['parallel'] = $enabled;
        $this->options['max_concurrency'] = $enabled ? max(1, $maxConcurrency) : 1;

        return $this;
    }

    /**
     * Set maximum concurrency for parallel execution.
     */
    public function withMaxConcurrency(int $maxConcurrency): self
    {
        $this->options['max_concurrency'] = max(1, $maxConcurrency);
        $this->options['parallel'] = $maxConcurrency > 1;

        return $this;
    }

    /**
     * Set the streaming logger.
     */
    public function withStreamingLogger(?Contracts\StreamingLoggerInterface $streamingLogger): self
    {
        $this->streamingLogger = $streamingLogger;

        return $this;
    }

    /**
     * Run the task chain.
     */
    public function run(): array
    {
        $this->startedAt = now()->toISOString();
        $this->results = [];
        $this->currentTaskIndex = 0;

        $totalTasks = $this->tasks->count();
        if ($totalTasks === 0) {
            throw new TaskChainException('Cannot run empty task chain');
        }

        // Dispatch chain started event
        event(new TaskChainStarted(
            $this->tasks->toArray(),
            $this->chainId,
            $this->startedAt,
            $this->options
        ));

        // Stream chain start
        $this->streamChainEvent('started', [
            'chain_id' => $this->chainId,
            'total_tasks' => $totalTasks,
            'options' => $this->options,
        ]);

        try {
            if ($this->options['parallel'] && $this->options['max_concurrency'] > 1) {
                return $this->runParallel();
            } else {
                return $this->runSequential();
            }
        } catch (Throwable $e) {
            $this->handleChainFailure($e->getMessage());
            throw $e;
        }
    }

    /**
     * Run tasks sequentially.
     */
    protected function runSequential(): array
    {
        $totalTasks = $this->tasks->count();

        foreach ($this->tasks as $index => $task) {
            $this->currentTaskIndex = $index;
            $taskName = $task->getName();
            $taskClass = get_class($task);

            // Stream task start
            $this->streamChainEvent('task_started', [
                'chain_id' => $this->chainId,
                'task_index' => $index,
                'task_name' => $taskName,
                'task_class' => $taskClass,
                'total_tasks' => $totalTasks,
                'current_task' => $index + 1,
            ]);

            // Update progress
            if ($this->options['progress_tracking']) {
                $this->updateProgress($index + 1, $totalTasks, "Running: {$taskName}");
            }

            try {
                // Execute the task
                $pendingTask = $task->pending();

                // Set timeout if specified
                if ($this->options['timeout']) {
                    $pendingTask->timeout($this->options['timeout']);
                }

                $result = $this->dispatcher->run($pendingTask);

                // Store the result
                $this->results[$index] = [
                    'task_name' => $taskName,
                    'task_class' => $taskClass,
                    'success' => $result ? $result->isSuccessful() : false,
                    'exit_code' => $result ? $result->getExitCode() : null,
                    'output' => $result ? $result->getBuffer() : '',
                    'duration' => $this->calculateTaskDuration(),
                    'completed_at' => now()->toISOString(),
                ];

                // Stream task completion
                $this->streamChainEvent('task_completed', [
                    'chain_id' => $this->chainId,
                    'task_index' => $index,
                    'task_name' => $taskName,
                    'success' => $this->results[$index]['success'],
                    'exit_code' => $this->results[$index]['exit_code'],
                    'duration' => $this->results[$index]['duration'],
                ]);

                // Check if we should stop on failure
                if ($this->options['stop_on_failure'] && ! $this->results[$index]['success']) {
                    $this->handleChainFailure("Task '{$taskName}' failed with exit code {$this->results[$index]['exit_code']}", $index);
                    break;
                }

            } catch (Throwable $e) {
                // Store the failure
                $this->results[$index] = [
                    'task_name' => $taskName,
                    'task_class' => $taskClass,
                    'success' => false,
                    'exit_code' => null,
                    'output' => '',
                    'error' => $e->getMessage(),
                    'exception' => $e,
                    'duration' => $this->calculateTaskDuration(),
                    'completed_at' => now()->toISOString(),
                ];

                // Stream task failure
                $this->streamChainEvent('task_failed', [
                    'chain_id' => $this->chainId,
                    'task_index' => $index,
                    'task_name' => $taskName,
                    'error' => $e->getMessage(),
                    'duration' => $this->results[$index]['duration'],
                ]);

                // Check if we should stop on failure
                if ($this->options['stop_on_failure']) {
                    $this->handleChainFailure("Task '{$taskName}' failed: {$e->getMessage()}", $index);
                    break;
                }
            }
        }

        return $this->processChainResults();
    }

    /**
     * Run tasks in parallel.
     */
    protected function runParallel(): array
    {
        $parallelExecutor = ParallelTaskExecutor::make($this->dispatcher, $this->streamingLogger);

        // Add all tasks to the parallel executor
        foreach ($this->tasks as $task) {
            $parallelExecutor->add($task);
        }

        // Configure the parallel executor with chain options
        $parallelExecutor->withOptions([
            'max_concurrency' => $this->options['max_concurrency'],
            'timeout' => $this->options['timeout'],
            'streaming' => $this->options['streaming'],
            'progress_tracking' => $this->options['progress_tracking'],
            'stop_on_failure' => $this->options['stop_on_failure'],
        ]);

        // Run the parallel execution
        $parallelResults = $parallelExecutor->run();

        // Convert parallel results to chain results format
        $this->results = $parallelResults['results'];

        return $this->processChainResults();
    }

    /**
     * Process the chain results.
     */
    protected function processChainResults(): array
    {
        $totalTasks = $this->tasks->count();
        $completedTasks = count($this->results);
        $successfulTasks = collect($this->results)->where('success', true)->count();
        $failedTasks = $completedTasks - $successfulTasks;

        $summary = [
            'chain_id' => $this->chainId,
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'successful_tasks' => $successfulTasks,
            'failed_tasks' => $failedTasks,
            'success_rate' => $completedTasks > 0 ? ($successfulTasks / $completedTasks) * 100 : 0,
            'started_at' => $this->startedAt,
            'completed_at' => now()->toISOString(),
            'duration' => $this->calculateChainDuration(),
            'results' => $this->results,
            'overall_success' => $this->isOverallSuccessful(),
        ];

        // Dispatch completion event
        if ($this->isOverallSuccessful()) {
            event(new TaskChainCompleted(
                $this->tasks->toArray(),
                $this->chainId,
                $summary,
                $this->startedAt
            ));
        } else {
            event(new TaskChainFailed(
                $this->tasks->toArray(),
                $this->chainId,
                $summary,
                $this->startedAt
            ));
        }

        // Stream chain completion
        $this->streamChainEvent('completed', [
            'chain_id' => $this->chainId,
            'success_rate' => $summary['success_rate'],
            'duration' => $summary['duration'],
            'overall_success' => $summary['overall_success'],
        ]);

        return $summary;
    }

    /**
     * Check if the overall chain was successful.
     */
    protected function isOverallSuccessful(): bool
    {
        if (empty($this->results)) {
            return false;
        }

        // Check if all completed tasks were successful
        return collect($this->results)->every(fn ($result) => $result['success']);
    }

    /**
     * Handle chain failure.
     */
    protected function handleChainFailure(string $reason, ?int $failedTaskIndex = null): void
    {
        $summary = [
            'chain_id' => $this->chainId,
            'total_tasks' => $this->tasks->count(),
            'completed_tasks' => count($this->results),
            'successful_tasks' => collect($this->results)->where('success', true)->count(),
            'failed_tasks' => count($this->results) - collect($this->results)->where('success', true)->count(),
            'started_at' => $this->startedAt,
            'failed_at' => now()->toISOString(),
            'duration' => $this->calculateChainDuration(),
            'failure_reason' => $reason,
            'failed_task_index' => $failedTaskIndex,
            'results' => $this->results,
            'overall_success' => false,
        ];

        event(new TaskChainFailed(
            $this->tasks->toArray(),
            $this->chainId,
            $summary,
            $this->startedAt
        ));

        // Stream chain failure
        $this->streamChainEvent('failed', [
            'chain_id' => $this->chainId,
            'reason' => $reason,
            'failed_task_index' => $failedTaskIndex,
            'duration' => $summary['duration'],
        ]);

        throw new TaskChainException("Task chain failed: {$reason}");
    }

    /**
     * Update progress tracking.
     */
    protected function updateProgress(int $current, int $total, string $message): void
    {
        if ($this->options['progress_tracking']) {
            event(new TaskChainProgress(
                $this->tasks->toArray(),
                $this->chainId,
                $current,
                $total,
                $message,
                $this->startedAt
            ));

            $this->streamChainEvent('progress', [
                'chain_id' => $this->chainId,
                'current_task' => $current,
                'total_tasks' => $total,
                'percentage' => ($current / $total) * 100,
                'message' => $message,
            ]);
        }
    }

    /**
     * Stream chain events.
     */
    protected function streamChainEvent(string $event, array $data = []): void
    {
        if ($this->options['streaming'] && $this->streamingLogger) {
            $this->streamingLogger->streamChainEvent($event, array_merge($data, [
                'timestamp' => now()->toISOString(),
            ]));
        }
    }

    /**
     * Calculate the duration of the current task.
     */
    protected function calculateTaskDuration(): float
    {
        // For now, return 0 as we don't track individual task start times
        // This could be enhanced to track per-task timing
        return 0.0;
    }

    /**
     * Calculate the duration of the entire chain.
     */
    protected function calculateChainDuration(): float
    {
        return now()->diffInSeconds($this->startedAt, true);
    }

    /**
     * Get the chain ID.
     */
    public function getChainId(): string
    {
        return $this->chainId;
    }

    /**
     * Get the tasks in the chain.
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    /**
     * Get the current task index.
     */
    public function getCurrentTaskIndex(): int
    {
        return $this->currentTaskIndex;
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
     * Check if the chain is empty.
     */
    public function isEmpty(): bool
    {
        return $this->tasks->isEmpty();
    }

    /**
     * Get the number of tasks in the chain.
     */
    public function count(): int
    {
        return $this->tasks->count();
    }

    /**
     * Clear all tasks from the chain.
     */
    public function clear(): self
    {
        $this->tasks = collect();

        return $this;
    }

    /**
     * Get aggregated output from all successful tasks.
     */
    public function getAggregatedOutput(): string
    {
        $outputs = [];
        foreach ($this->results as $index => $result) {
            if ($result['success'] && ! empty($result['output'])) {
                $taskName = $result['task_name'];
                $outputs[] = "=== {$taskName} ===\n{$result['output']}";
            }
        }

        return implode("\n\n", $outputs);
    }

    /**
     * Get aggregated errors from all failed tasks.
     */
    public function getAggregatedErrors(): string
    {
        $errors = [];
        foreach ($this->results as $index => $result) {
            if (! $result['success']) {
                $taskName = $result['task_name'];
                $error = $result['error'] ?? 'Unknown error';
                $errors[] = "=== {$taskName} ===\n{$error}";
            }
        }

        return implode("\n\n", $errors);
    }
}
