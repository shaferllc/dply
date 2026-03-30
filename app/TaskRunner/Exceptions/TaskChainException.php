<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use Exception;

class TaskChainException extends Exception
{
    /**
     * The failed task number.
     */
    protected ?int $failedTaskNumber = null;

    /**
     * The task chain results.
     */
    protected array $chainResults = [];

    /**
     * Create a new TaskChainException instance.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Exception $previous = null,
        ?int $failedTaskNumber = null,
        array $chainResults = []
    ) {
        parent::__construct($message, $code, $previous);

        $this->failedTaskNumber = $failedTaskNumber;
        $this->chainResults = $chainResults;
    }

    /**
     * Get the failed task number.
     */
    public function getFailedTaskNumber(): ?int
    {
        return $this->failedTaskNumber;
    }

    /**
     * Get the chain results.
     */
    public function getChainResults(): array
    {
        return $this->chainResults;
    }

    /**
     * Set the chain results.
     */
    public function setChainResults(array $results): self
    {
        $this->chainResults = $results;

        return $this;
    }

    /**
     * Check if any tasks were successful before failure.
     */
    public function hasSuccessfulTasks(): bool
    {
        return ! empty($this->chainResults['successful_tasks'] ?? 0);
    }

    /**
     * Get the number of successful tasks.
     */
    public function getSuccessfulTaskCount(): int
    {
        return $this->chainResults['successful_tasks'] ?? 0;
    }

    /**
     * Get the number of failed tasks.
     */
    public function getFailedTaskCount(): int
    {
        return $this->chainResults['failed_tasks'] ?? 0;
    }

    /**
     * Get the total number of tasks in the chain.
     */
    public function getTotalTaskCount(): int
    {
        return $this->chainResults['total_tasks'] ?? 0;
    }
}
