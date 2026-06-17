<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\ConnectionManager;
use App\Modules\TaskRunner\MultiServerDispatcher;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\Task;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait DispatchesTaskRunnerToServers
{
    /**
     * Dispatch a task for execution.
     *
     * @param  array<string, mixed>  $arguments
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
     *
     * @param  list<Connection|array<string, mixed>|string>  $connections
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToMultipleServers(Task $task, array $connections, array $options = []): array
    {
        $multiServerDispatcher = new MultiServerDispatcher($this);

        return $multiServerDispatcher->dispatch($task, $connections, $options);
    }

    /**
     * Dispatch a task to multiple servers using various connection sources.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToMultipleConnections(Task $task, mixed $connectionSources, array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createConnections($connectionSources);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from database query.
     *
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $orderBy
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToDatabaseServers(Task $task, string $table, array $where = [], array $orderBy = [], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromQuery($table, $where, $orderBy);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from model query.
     *
     * @param  array<string, mixed>  $where
     * @param  array<string, mixed>  $orderBy
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToModelServers(Task $task, string $modelClass, array $where = [], array $orderBy = [], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromModelQuery($modelClass, $where, $orderBy);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers by group.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToGroup(Task $task, string $groupName, string $table = 'servers', array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromGroup($groupName, $table);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers by tags.
     *
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToTaggedServers(Task $task, array $tags, string $table = 'servers', array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromTags($tags, $table);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from environment variables.
     *
     * @param  list<string>  $prefixes
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToEnvironmentServers(Task $task, array $prefixes = ['SSH_'], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromEnvironment($prefixes);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from JSON file.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToJsonFileServers(Task $task, string $filePath, array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromJsonFile($filePath);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch a task to servers from CSV file.
     *
     * @param  array<string, mixed>  $columnMapping
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToCsvFileServers(Task $task, string $filePath, array $columnMapping = [], array $options = []): array
    {
        $connectionManager = app(ConnectionManager::class);
        $connections = $connectionManager->createFromCsvFile($filePath, $columnMapping);

        return $this->dispatchToMultipleServers($task, $connections->toArray(), $options);
    }

    /**
     * Dispatch an anonymous task to multiple servers.
     *
     * @param  list<Connection|array<string, mixed>|string>  $connections
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchAnonymousToMultipleServers(AnonymousTask $task, array $connections, array $options = []): array
    {
        return $this->dispatchToMultipleServers($task, $connections, $options);
    }
}
