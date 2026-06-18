<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Connection;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskStarted
{
    use Dispatchable, SerializesModels;

    /**
     * The task instance.
     */
    public Task $task;

    /**
     * The pending task instance.
     */
    public PendingTask $pendingTask;

    /**
     * The task start timestamp.
     */
    public string $startedAt;

    /**
     * Additional context data.
     */
    /** @var array<string, mixed> */
    public array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(Task $task, PendingTask $pendingTask, array $context = [])
    {
        $this->task = $task;
        $this->pendingTask = $pendingTask;
        $this->startedAt = now()->toISOString();
        $this->context = $context;
    }

    public function getTaskName(): string
    {
        return $this->task->getName();
    }

    /**
     * Get the task action.
     */
    public function getTaskAction(): string
    {
        return $this->task->getAction();
    }

    /**
     * Get the task class.
     */
    public function getTaskClass(): string
    {
        return get_class($this->task);
    }

    /**
     * Get the task timeout.
     */
    public function getTaskTimeout(): ?int
    {
        return $this->task->getTimeout();
    }

    /**
     * Check if the task is running in background.
     */
    public function isBackground(): bool
    {
        return $this->pendingTask->shouldRunInBackground();
    }

    /**
     * Get the connection (object) if running remotely.
     */
    public function getConnection(): ?Connection
    {
        return $this->pendingTask->getConnection();
    }

    /**
     * Get the connection name if running remotely.
     */
    public function getConnectionName(): ?string
    {
        return $this->pendingTask->connectionName;
    }

    /**
     * Get the task ID.
     */
    public function getTaskId(): ?string
    {
        return $this->pendingTask->getId();
    }

    /**
     * Get the task data.
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function getTaskData(): array
    {
        return $this->task->getData();
    }

    public function getTaskScript(): string
    {
        return $this->task->getScript();
    }

    public function getTaskView(): string
    {
        return $this->task->getView();
    }
}
