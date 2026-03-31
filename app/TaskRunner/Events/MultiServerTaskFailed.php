<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MultiServerTaskFailed
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
     * The task summary.
     */
    public array $summary;

    /**
     * The task start timestamp.
     */
    public string $startedAt;

    /**
     * Create a new event instance.
     */
    public function __construct(
        Task $task,
        array $connections,
        string $multiServerTaskId,
        array $summary,
        string $startedAt
    ) {
        $this->task = $task;
        $this->connections = $connections;
        $this->multiServerTaskId = $multiServerTaskId;
        $this->summary = $summary;
        $this->startedAt = $startedAt;
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
     * Get the total number of servers.
     */
    public function getTotalServers(): int
    {
        return $this->summary['total_servers'] ?? 0;
    }

    /**
     * Get the number of successful servers.
     */
    public function getSuccessfulServers(): int
    {
        return $this->summary['successful_servers'] ?? 0;
    }

    /**
     * Get the number of failed servers.
     */
    public function getFailedServers(): int
    {
        return $this->summary['failed_servers'] ?? 0;
    }

    /**
     * Get the success rate percentage.
     */
    public function getSuccessRate(): float
    {
        return $this->summary['success_rate'] ?? 0.0;
    }

    /**
     * Get the task duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->summary['duration'] ?? 0.0;
    }

    /**
     * Get the task duration in a human-readable format.
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
     * Get the successful connections.
     */
    public function getSuccessfulConnections(): array
    {
        return $this->summary['successful_connections'] ?? [];
    }

    /**
     * Get the failed connections.
     */
    public function getFailedConnections(): array
    {
        return $this->summary['failed_connections'] ?? [];
    }

    /**
     * Get the results from all servers.
     */
    public function getResults(): array
    {
        return $this->summary['results'] ?? [];
    }

    /**
     * Check if the overall task was successful.
     */
    public function wasSuccessful(): bool
    {
        return $this->summary['overall_success'] ?? false;
    }

    /**
     * Get the error message.
     */
    public function getErrorMessage(): string
    {
        return $this->summary['error'] ?? '';
    }

    /**
     * Get the failed connection.
     */
    public function getFailedConnection(): ?string
    {
        return $this->summary['failed_connection'] ?? null;
    }

    /**
     * Get the failure timestamp.
     */
    public function getFailedAt(): string
    {
        return $this->summary['failed_at'] ?? '';
    }

    /**
     * Get failure details.
     */
    public function getFailureDetails(): array
    {
        return [
            'total_servers' => $this->getTotalServers(),
            'successful_servers' => $this->getSuccessfulServers(),
            'failed_servers' => $this->getFailedServers(),
            'success_rate' => $this->getSuccessRate(),
            'duration' => $this->getDuration(),
            'duration_human' => $this->getDurationForHumans(),
            'error_message' => $this->getErrorMessage(),
            'failed_connection' => $this->getFailedConnection(),
            'started_at' => $this->startedAt,
            'failed_at' => $this->getFailedAt(),
        ];
    }
}
