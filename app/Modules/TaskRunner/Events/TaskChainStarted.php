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
    /** @var array<string, mixed> */
    public array $tasks;

    public string $chainId;

    public string $startedAt;

    /** @var array<string, mixed> */
    public array $options;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $tasks
     */
    public function __construct(array $tasks, string $chainId, string $startedAt, array $options = [])
    {
        $this->tasks = $tasks;
        $this->chainId = $chainId;
        $this->startedAt = $startedAt;
        $this->options = $options;
    }

    public function getTaskCount(): int
    {
        return count($this->tasks);
    }

    /**
     * Get the task names.
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function getTaskNames(): array
    {
        return array_map(fn ($task) => $task->getName(), $this->tasks);
    }

    /** @return array<string, mixed> */
    public function getTaskClasses(): array
    {
        return array_map(fn ($task) => get_class($task), $this->tasks);
    }

    public function isStreamingEnabled(): bool
    {
        return $this->options['streaming'] ?? true;
    }

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
