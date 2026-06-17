<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskChainFailed
{
    use Dispatchable, SerializesModels;

    /**
     * The tasks in the chain.
     */
    /** @var array<string, mixed> */
    public array $tasks;

    public string $chainId;

    /** @var array<string, mixed> */
    public array $summary;

    public string $startedAt;

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $tasks
     */
    public function __construct(array $tasks, string $chainId, array $summary, string $startedAt)
    {
        $this->tasks = $tasks;
        $this->chainId = $chainId;
        $this->summary = $summary;
        $this->startedAt = $startedAt;
    }

    public function getTotalTasks(): int
    {
        return $this->summary['total_tasks'] ?? 0;
    }

    /**
     * Get the number of completed tasks.
     */
    public function getCompletedTasks(): int
    {
        return $this->summary['completed_tasks'] ?? 0;
    }

    /**
     * Get the number of successful tasks.
     */
    public function getSuccessfulTasks(): int
    {
        return $this->summary['successful_tasks'] ?? 0;
    }

    /**
     * Get the number of failed tasks.
     */
    public function getFailedTasks(): int
    {
        return $this->summary['failed_tasks'] ?? 0;
    }

    /**
     * Get the success rate percentage.
     */
    public function getSuccessRate(): float
    {
        return $this->summary['success_rate'] ?? 0.0;
    }

    /**
     * Get the chain duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->summary['duration'] ?? 0.0;
    }

    /**
     * Get the chain duration in a human-readable format.
     */
    public function getDurationForHumans(): string
    {
        $duration = $this->getDuration();

        if ($duration < 1) {
            return round($duration * 1000, 2).'ms';
        }

        if ($duration < 60) {
            return round($duration, 2).'s';
        }

        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        return "{$minutes}m ".round($seconds, 2).'s';
    }

    /**
     * Get the failure timestamp.
     */
    public function getFailedAt(): string
    {
        return $this->summary['failed_at'] ?? '';
    }

    /**
     * Get the failure reason.
     */
    public function getFailureReason(): string
    {
        return $this->summary['failure_reason'] ?? '';
    }

    /**
     * Get the failed task index.
     */
    public function getFailedTaskIndex(): ?int
    {
        return $this->summary['failed_task_index'] ?? null;
    }

    /**
     * Get the task results.
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function getResults(): array
    {
        return $this->summary['results'] ?? [];
    }

    public function wasSuccessful(): bool
    {
        return $this->summary['overall_success'] ?? false;
    }

    /** @return array<string, mixed> */
    public function getFailureDetails(): array
    {
        return [
            'total_tasks' => $this->getTotalTasks(),
            'completed_tasks' => $this->getCompletedTasks(),
            'successful_tasks' => $this->getSuccessfulTasks(),
            'failed_tasks' => $this->getFailedTasks(),
            'success_rate' => $this->getSuccessRate(),
            'duration' => $this->getDuration(),
            'duration_human' => $this->getDurationForHumans(),
            'failure_reason' => $this->getFailureReason(),
            'failed_task_index' => $this->getFailedTaskIndex(),
            'started_at' => $this->startedAt,
            'failed_at' => $this->getFailedAt(),
        ];
    }
}
