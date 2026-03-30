<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskCompleted
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
     * The process output.
     */
    public ProcessOutput $output;

    /**
     * The task start timestamp.
     */
    public string $startedAt;

    /**
     * The task completion timestamp.
     */
    public string $completedAt;

    /**
     * The task duration in seconds.
     */
    public float $duration;

    /**
     * Additional context data.
     */
    public array $context;

    /**
     * Create a new event instance.
     */
    public function __construct(
        Task $task,
        PendingTask $pendingTask,
        ProcessOutput $output,
        string $startedAt,
        array $context = []
    ) {
        $this->task = $task;
        $this->pendingTask = $pendingTask;
        $this->output = $output;
        $this->startedAt = $startedAt;
        $this->completedAt = now()->toISOString();
        $this->duration = now()->diffInSeconds($startedAt, true);
        $this->context = $context;
    }

    /**
     * Get the task name.
     */
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
     * Get the exit code.
     */
    public function getExitCode(): int
    {
        return $this->output->getExitCode();
    }

    /**
     * Check if the task was successful.
     */
    public function wasSuccessful(): bool
    {
        return $this->output->getExitCode() === 0;
    }

    /**
     * Get the task output.
     */
    public function getOutput(): string
    {
        return $this->output->getBuffer();
    }

    /**
     * Get the task error output.
     */
    public function getErrorOutput(): string
    {
        // ProcessOutput doesn't separate stdout/stderr, so return empty for now
        return '';
    }

    /**
     * Check if the task timed out.
     */
    public function timedOut(): bool
    {
        return $this->output->isTimeout();
    }

    /**
     * Get the task duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->duration;
    }

    /**
     * Get the task duration in a human-readable format.
     */
    public function getDurationForHumans(): string
    {
        if ($this->duration < 1) {
            return round($this->duration * 1000, 2).'ms';
        }

        if ($this->duration < 60) {
            return round($this->duration, 2).'s';
        }

        $minutes = floor($this->duration / 60);
        $seconds = $this->duration % 60;

        return "{$minutes}m ".round($seconds, 2).'s';
    }

    /**
     * Check if the task is running in background.
     */
    public function isBackground(): bool
    {
        return $this->pendingTask->shouldRunInBackground();
    }

    /**
     * Get the connection name if running remotely.
     */
    public function getConnection(): ?string
    {
        return $this->pendingTask->getConnectionName();
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
     */
    public function getTaskData(): array
    {
        return $this->task->getData();
    }

    /**
     * Get the task script.
     */
    public function getTaskScript(): string
    {
        return $this->task->getScript();
    }

    /**
     * Get the task view.
     */
    public function getTaskView(): string
    {
        return $this->task->getView();
    }

    /**
     * Get performance metrics.
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'duration' => $this->duration,
            'duration_human' => $this->getDurationForHumans(),
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'output_size' => strlen($this->output->getBuffer()),
            'error_size' => 0, // ProcessOutput doesn't separate stdout/stderr
        ];
    }
}
