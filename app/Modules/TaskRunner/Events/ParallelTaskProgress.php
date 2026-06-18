<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use Illuminate\Queue\SerializesModels;

class ParallelTaskProgress
{
    use SerializesModels;

    /**
     * The execution ID.
     */
    public string $executionId;

    /**
     * The current number of completed tasks.
     */
    public int $current;

    /**
     * The total number of tasks.
     */
    public int $total;

    /**
     * The progress percentage.
     */
    public float $percentage;

    /**
     * The progress message.
     */
    public string $message;

    /**
     * The progress timestamp.
     */
    public string $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $executionId,
        int $current,
        int $total,
        float $percentage,
        string $message,
        string $timestamp
    ) {
        $this->executionId = $executionId;
        $this->current = $current;
        $this->total = $total;
        $this->percentage = $percentage;
        $this->message = $message;
        $this->timestamp = $timestamp;
    }

    /**
     * Get the progress percentage as an integer.
     */
    public function getPercentageInt(): int
    {
        return (int) round($this->percentage);
    }

    /**
     * Check if the execution is complete.
     */
    public function isComplete(): bool
    {
        return $this->current >= $this->total;
    }

    /**
     * Get the remaining tasks.
     */
    public function getRemaining(): int
    {
        return max(0, $this->total - $this->current);
    }

    /**
     * Get the progress ratio (0.0 to 1.0).
     */
    public function getProgressRatio(): float
    {
        return $this->total > 0 ? $this->current / $this->total : 0;
    }
}
