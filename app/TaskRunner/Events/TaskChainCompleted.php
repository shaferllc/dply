<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Queue\SerializesModels;

class TaskChainCompleted
{
    use SerializesModels;

    /**
     * The tasks in the chain.
     */
    public array $tasks;

    /**
     * The chain ID.
     */
    public string $chainId;

    /**
     * The chain summary.
     */
    public array $summary;

    /**
     * The start timestamp.
     */
    public string $startedAt;

    /**
     * Create a new event instance.
     */
    public function __construct(array $tasks, string $chainId, array $summary, string $startedAt)
    {
        $this->tasks = $tasks;
        $this->chainId = $chainId;
        $this->summary = $summary;
        $this->startedAt = $startedAt;
    }

    /**
     * Get the total number of tasks.
     */
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
     * Get the completion timestamp.
     */
    public function getCompletedAt(): string
    {
        return $this->summary['completed_at'] ?? '';
    }

    /**
     * Get the task results.
     */
    public function getResults(): array
    {
        return $this->summary['results'] ?? [];
    }

    /**
     * Check if the overall chain was successful.
     */
    public function wasSuccessful(): bool
    {
        return $this->summary['overall_success'] ?? false;
    }

    /**
     * Get performance metrics.
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'total_tasks' => $this->getTotalTasks(),
            'completed_tasks' => $this->getCompletedTasks(),
            'successful_tasks' => $this->getSuccessfulTasks(),
            'failed_tasks' => $this->getFailedTasks(),
            'success_rate' => $this->getSuccessRate(),
            'duration' => $this->getDuration(),
            'duration_human' => $this->getDurationForHumans(),
            'started_at' => $this->startedAt,
            'completed_at' => $this->getCompletedAt(),
        ];
    }
}
