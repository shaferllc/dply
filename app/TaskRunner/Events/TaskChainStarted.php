<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskChainStarted
{
    use Dispatchable, SerializesModels;

    /**
     * The tasks in the chain.
     */
    public array $tasks;

    /**
     * The chain ID.
     */
    public string $chainId;

    /**
     * The start timestamp.
     */
    public string $startedAt;

    /**
     * The execution options.
     */
    public array $options;

    /**
     * Create a new event instance.
     */
    public function __construct(array $tasks, string $chainId, string $startedAt, array $options = [])
    {
        $this->tasks = $tasks;
        $this->chainId = $chainId;
        $this->startedAt = $startedAt;
        $this->options = $options;
    }

    /**
     * Get the number of tasks in the chain.
     */
    public function getTaskCount(): int
    {
        return count($this->tasks);
    }

    /**
     * Get the task names.
     */
    public function getTaskNames(): array
    {
        return array_map(fn ($task) => $task->getName(), $this->tasks);
    }

    /**
     * Get the task classes.
     */
    public function getTaskClasses(): array
    {
        return array_map(fn ($task) => get_class($task), $this->tasks);
    }

    /**
     * Check if streaming is enabled.
     */
    public function isStreamingEnabled(): bool
    {
        return $this->options['streaming'] ?? true;
    }

    /**
     * Check if progress tracking is enabled.
     */
    public function isProgressTrackingEnabled(): bool
    {
        return $this->options['progress_tracking'] ?? true;
    }

    /**
     * Check if the chain stops on failure.
     */
    public function stopsOnFailure(): bool
    {
        return $this->options['stop_on_failure'] ?? true;
    }

    /**
     * Get the timeout value.
     */
    public function getTimeout(): ?int
    {
        return $this->options['timeout'] ?? null;
    }
}
