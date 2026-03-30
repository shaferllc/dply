<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MultiServerTaskStarted
{
    use Dispatchable, SerializesModels;

    /**
     * The task instance.
     */
    public Task $task;

    /**
     * The server connections.
     */
    public array $connections;

    /**
     * The multi-server task ID.
     */
    public string $multiServerTaskId;

    /**
     * The task start timestamp.
     */
    public string $startedAt;

    /**
     * The execution options.
     */
    public array $options;

    /**
     * Create a new event instance.
     */
    public function __construct(
        Task $task,
        array $connections,
        string $multiServerTaskId,
        string $startedAt,
        array $options = []
    ) {
        $this->task = $task;
        $this->connections = $connections;
        $this->multiServerTaskId = $multiServerTaskId;
        $this->startedAt = $startedAt;
        $this->options = $options;
    }

    /**
     * Get the task name.
     */
    public function getTaskName(): string
    {
        return $this->task->getName();
    }

    /**
     * Get the task class.
     */
    public function getTaskClass(): string
    {
        return get_class($this->task);
    }

    /**
     * Get the number of servers.
     */
    public function getServerCount(): int
    {
        return count($this->connections);
    }

    /**
     * Check if execution is parallel.
     */
    public function isParallel(): bool
    {
        return $this->options['parallel'] ?? true;
    }

    /**
     * Get the timeout value.
     */
    public function getTimeout(): ?int
    {
        return $this->options['timeout'] ?? null;
    }

    /**
     * Check if execution stops on failure.
     */
    public function stopsOnFailure(): bool
    {
        return $this->options['stop_on_failure'] ?? false;
    }

    /**
     * Get the minimum success requirement.
     */
    public function getMinSuccess(): ?int
    {
        return $this->options['min_success'] ?? null;
    }

    /**
     * Get the maximum failures allowed.
     */
    public function getMaxFailures(): ?int
    {
        return $this->options['max_failures'] ?? null;
    }
}
