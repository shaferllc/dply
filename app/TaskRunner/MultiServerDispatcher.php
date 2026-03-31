<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Events\MultiServerTaskCompleted;
use App\Modules\TaskRunner\Events\MultiServerTaskFailed;
use App\Modules\TaskRunner\Events\MultiServerTaskStarted;
use App\Modules\TaskRunner\Exceptions\MultiServerTaskException;
use Throwable;

class MultiServerDispatcher
{
    /**
     * The task dispatcher instance.
     */
    protected TaskDispatcher $dispatcher;

    /**
     * The multi-server task ID.
     */
    protected string $multiServerTaskId;

    /**
     * The task to dispatch.
     */
    protected Task $task;

    /**
     * The server connections.
     */
    protected array $connections;

    /**
     * The connection manager.
     */
    protected ?ConnectionManager $connectionManager = null;

    /**
     * The execution options.
     */
    protected array $options;

    /**
     * The results from each server.
     */
    protected array $results = [];

    /**
     * The failed servers.
     */
    protected array $failedServers = [];

    /**
     * The successful servers.
     */
    protected array $successfulServers = [];

    /**
     * The start timestamp.
     */
    protected string $startedAt;

    /**
     * Create a new MultiServerDispatcher instance.
     */
    public function __construct(TaskDispatcher $dispatcher, ?ConnectionManager $connectionManager = null)
    {
        $this->dispatcher = $dispatcher;
        $this->connectionManager = $connectionManager ?? app(ConnectionManager::class);
        $this->multiServerTaskId = uniqid('multi_', true);
    }

    /**
     * Dispatch a task to multiple servers.
     */
    public function dispatch(Task $task, array $connections, array $options = []): array
    {
        $this->task = $task;

        // Convert connection sources to Connection objects if needed
        $this->connections = $this->normalizeConnections($connections);
        $this->options = array_merge([
            'parallel' => true,
            'timeout' => null,
            'stop_on_failure' => false,
            'wait_for_all' => true,
            'min_success' => null,
            'max_failures' => null,
        ], $options);

        $this->startedAt = now()->toISOString();
        $this->results = [];
        $this->failedServers = [];
        $this->successfulServers = [];

        // Dispatch multi-server task started event
        event(new MultiServerTaskStarted(
            $this->task,
            $this->connections,
            $this->multiServerTaskId,
            $this->startedAt,
            $this->options
        ));

        try {
            if ($this->options['parallel']) {
                return $this->dispatchParallel();
            } else {
                return $this->dispatchSequential();
            }
        } catch (Throwable $e) {
            $this->handleMultiServerFailure($e);
            throw $e;
        }
    }

    /**
     * Dispatch tasks to all servers in parallel.
     */
    protected function dispatchParallel(): array
    {
        $promises = [];
        $serverResults = [];

        // Create promises for each server
        foreach ($this->connections as $connection) {
            $connectionString = (string) $connection;
            $promises[$connectionString] = $this->createTaskPromise($connection);
        }

        // Wait for all promises to resolve
        foreach ($promises as $connectionString => $promise) {
            try {
                $result = $promise();
                $serverResults[$connectionString] = $result;
                if ($result['success']) {
                    $this->successfulServers[] = $connectionString;
                } else {
                    $this->failedServers[] = $connectionString;
                    // Check if we should stop on failure
                    if ($this->options['stop_on_failure']) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                $serverResults[$connectionString] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ];
                $this->failedServers[] = $connectionString;

                // Check if we should stop on failure
                if ($this->options['stop_on_failure']) {
                    $this->handleMultiServerFailure($e, (string) $connection);
                    break;
                }
            }
        }

        $this->results = $serverResults;

        return $this->processResults();
    }

    /**
     * Dispatch tasks to servers sequentially.
     */
    protected function dispatchSequential(): array
    {
        $serverResults = [];

        foreach ($this->connections as $connection) {
            $connectionString = (string) $connection;
            try {
                $result = $this->executeTaskOnServer($connection);
                $serverResults[$connectionString] = $result;
                if ($result['success']) {
                    $this->successfulServers[] = $connectionString;
                } else {
                    $this->failedServers[] = $connectionString;
                    // Check if we should stop on failure
                    if ($this->options['stop_on_failure']) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                $serverResults[$connectionString] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ];
                $this->failedServers[] = $connectionString;

                // Check if we should stop on failure
                if ($this->options['stop_on_failure']) {
                    $this->handleMultiServerFailure($e, (string) $connection);
                    break;
                }
            }
        }

        $this->results = $serverResults;

        return $this->processResults();
    }

    /**
     * Normalize connections to Connection objects.
     */
    protected function normalizeConnections(array $connections): array
    {
        return collect($connections)->map(function ($connection) {
            if ($connection instanceof Connection) {
                return $connection;
            }

            return $this->connectionManager->createConnection($connection);
        })->toArray();
    }

    /**
     * Create a promise for task execution on a specific server.
     */
    protected function createTaskPromise(Connection $connection): callable
    {
        return function () use ($connection) {
            return $this->executeTaskOnServer($connection);
        };
    }

    /**
     * Execute the task on a specific server.
     */
    protected function executeTaskOnServer(Connection $connection): array
    {
        $pendingTask = $this->task->pending()
            ->onConnection($connection);

        if ($this->options['timeout']) {
            $pendingTask->timeout($this->options['timeout']);
        }

        try {
            $output = $this->dispatcher->run($pendingTask);

            return [
                'success' => $output->isSuccessful(),
                'exit_code' => $output->getExitCode(),
                'output' => $output->getBuffer(),
                'duration' => $this->calculateDuration(),
                'connection' => $connection,
                'connection_string' => (string) $connection,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'exception' => $e,
                'connection' => $connection,
                'connection_string' => (string) $connection,
            ];
        }
    }

    /**
     * Process the results from all servers.
     */
    protected function processResults(): array
    {
        $totalServers = count($this->connections);
        $successfulCount = count($this->successfulServers);
        $failedCount = count($this->failedServers);

        $summary = [
            'multi_server_task_id' => $this->multiServerTaskId,
            'task_name' => $this->task->getName(),
            'task_class' => get_class($this->task),
            'total_servers' => $totalServers,
            'successful_servers' => $successfulCount,
            'failed_servers' => $failedCount,
            'success_rate' => $totalServers > 0 ? ($successfulCount / $totalServers) * 100 : 0,
            'started_at' => $this->startedAt,
            'completed_at' => now()->toISOString(),
            'duration' => $this->calculateDuration(),
            'successful_connections' => $this->successfulServers,
            'failed_connections' => $this->failedServers,
            'results' => $this->results,
            'overall_success' => $this->isOverallSuccessful(),
        ];

        // Dispatch completion event
        if ($this->isOverallSuccessful()) {
            event(new MultiServerTaskCompleted(
                $this->task,
                $this->connections,
                $this->multiServerTaskId,
                $summary,
                $this->startedAt
            ));
        } else {
            event(new MultiServerTaskFailed(
                $this->task,
                $this->connections,
                $this->multiServerTaskId,
                $summary,
                $this->startedAt
            ));
        }

        return $summary;
    }

    /**
     * Check if the overall multi-server task was successful.
     */
    protected function isOverallSuccessful(): bool
    {
        $totalServers = count($this->connections);
        $successfulCount = count($this->successfulServers);

        // Check minimum success requirement
        if ($this->options['min_success'] !== null) {
            return $successfulCount >= $this->options['min_success'];
        }

        // Check maximum failures requirement
        if ($this->options['max_failures'] !== null) {
            $failedCount = count($this->failedServers);

            return $failedCount <= $this->options['max_failures'];
        }

        // Default: all servers must succeed
        return $successfulCount === $totalServers;
    }

    /**
     * Handle multi-server task failure.
     */
    protected function handleMultiServerFailure(Throwable $e, ?string $failedConnectionString = null): void
    {
        $summary = [
            'multi_server_task_id' => $this->multiServerTaskId,
            'task_name' => $this->task->getName(),
            'task_class' => get_class($this->task),
            'total_servers' => count($this->connections),
            'successful_servers' => count($this->successfulServers),
            'failed_servers' => count($this->failedServers),
            'started_at' => $this->startedAt,
            'failed_at' => now()->toISOString(),
            'duration' => $this->calculateDuration(),
            'error' => $e->getMessage(),
            'exception' => $e,
            'failed_connection' => $failedConnectionString,
            'results' => $this->results,
            'overall_success' => false,
        ];

        event(new MultiServerTaskFailed(
            $this->task,
            $this->connections,
            $this->multiServerTaskId,
            $summary,
            $this->startedAt
        ));

        throw new MultiServerTaskException(
            "Multi-server task failed: {$e->getMessage()}",
            previous: $e
        );
    }

    /**
     * Calculate the duration of the multi-server task.
     */
    protected function calculateDuration(): float
    {
        return now()->diffInSeconds($this->startedAt, true);
    }

    /**
     * Get the multi-server task ID.
     */
    public function getMultiServerTaskId(): string
    {
        return $this->multiServerTaskId;
    }

    /**
     * Get the results from all servers.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get the successful servers.
     */
    public function getSuccessfulServers(): array
    {
        return $this->successfulServers;
    }

    /**
     * Get the failed servers.
     */
    public function getFailedServers(): array
    {
        return $this->failedServers;
    }

    /**
     * Check if a specific server was successful.
     */
    public function wasServerSuccessful(string $connection): bool
    {
        return in_array($connection, $this->successfulServers);
    }

    /**
     * Get the result for a specific server.
     */
    public function getServerResult(string $connection): ?array
    {
        return $this->results[$connection] ?? null;
    }

    /**
     * Get the output from a specific server.
     */
    public function getServerOutput(string $connection): string
    {
        $result = $this->getServerResult($connection);

        return $result['output'] ?? '';
    }

    /**
     * Get the exit code from a specific server.
     */
    public function getServerExitCode(string $connection): ?int
    {
        $result = $this->getServerResult($connection);

        return $result['exit_code'] ?? null;
    }

    /**
     * Get aggregated output from all successful servers.
     */
    public function getAggregatedOutput(): string
    {
        $outputs = [];
        foreach ($this->successfulServers as $connection) {
            $output = $this->getServerOutput($connection);
            if ($output) {
                $outputs[] = "=== {$connection} ===\n{$output}";
            }
        }

        return implode("\n\n", $outputs);
    }

    /**
     * Get aggregated error output from all failed servers.
     */
    public function getAggregatedErrors(): string
    {
        $errors = [];
        foreach ($this->failedServers as $connection) {
            $result = $this->getServerResult($connection);
            $error = $result['error'] ?? 'Unknown error';
            $errors[] = "=== {$connection} ===\n{$error}";
        }

        return implode("\n\n", $errors);
    }
}
