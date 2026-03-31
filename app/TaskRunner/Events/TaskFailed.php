<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class TaskFailed
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
     * The process output (if available).
     */
    public ?ProcessOutput $output;

    /**
     * The exception that caused the failure.
     */
    public ?Throwable $exception;

    /**
     * The task start timestamp.
     */
    public string $startedAt;

    /**
     * The task failure timestamp.
     */
    public string $failedAt;

    /**
     * The task duration in seconds.
     */
    public float $duration;

    /**
     * The failure reason.
     */
    public string $reason;

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
        ?ProcessOutput $output,
        ?Throwable $exception,
        string $startedAt,
        string $reason,
        array $context = []
    ) {
        $this->task = $task;
        $this->pendingTask = $pendingTask;
        $this->output = $output;
        $this->exception = $exception;
        $this->startedAt = $startedAt;
        $this->failedAt = now()->toISOString();
        $this->duration = now()->diffInSeconds($startedAt, true);
        $this->reason = $reason;
        $this->context = $context;
    }

    /**
     * Exclude unserializable properties from serialization.
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);

        // PendingTask already excludes its closure, but if any other unserializable properties exist, exclude them here.
        return $data;
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
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
    public function getExitCode(): ?int
    {
        return $this->output?->getExitCode();
    }

    /**
     * Get the task output.
     */
    public function getOutput(): string
    {
        return $this->output?->getBuffer() ?? '';
    }

    /**
     * Check if the task timed out.
     */
    public function timedOut(): bool
    {
        return $this->output?->isTimeout() ?? false;
    }

    /**
     * Get the exception message.
     */
    public function getExceptionMessage(): string
    {
        return $this->exception?->getMessage() ?? '';
    }

    /**
     * Get the exception class.
     */
    public function getExceptionClass(): string
    {
        return $this->exception ? get_class($this->exception) : '';
    }

    /**
     * Get the exception trace.
     */
    public function getExceptionTrace(): array
    {
        return $this->exception?->getTrace() ?? [];
    }

    /**
     * Get the failure reason.
     */
    public function getReason(): string
    {
        return $this->reason;
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
     * Get failure details.
     */
    public function getFailureDetails(): array
    {
        return [
            'reason' => $this->reason,
            'exception_class' => $this->getExceptionClass(),
            'exception_message' => $this->getExceptionMessage(),
            'exit_code' => $this->getExitCode(),
            'timed_out' => $this->timedOut(),
            'duration' => $this->duration,
            'duration_human' => $this->getDurationForHumans(),
            'started_at' => $this->startedAt,
            'failed_at' => $this->failedAt,
            'output_size' => strlen($this->getOutput()),
        ];
    }

    /**
     * Check if the failure was due to a timeout.
     */
    public function wasTimeout(): bool
    {
        return $this->timedOut();
    }

    /**
     * Check if the failure was due to an exception.
     */
    public function wasException(): bool
    {
        return $this->exception !== null;
    }

    /**
     * Check if the failure was due to a non-zero exit code.
     */
    public function wasExitCode(): bool
    {
        return $this->getExitCode() !== null && $this->getExitCode() !== 0;
    }
}
