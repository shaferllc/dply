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
    /** @var array<string, mixed> */
    protected array $chainResults = [];

    /**
     * @param array<string, mixed> $chainResults
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
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function getChainResults(): array
    {
        return $this->chainResults;
    }

    /**
     * @param array<string, mixed> $results
     */
    public function setChainResults(array $results): self
    {
        $this->chainResults = $results;

        return $this;
    }

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
