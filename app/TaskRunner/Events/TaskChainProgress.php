<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\Task;
use Illuminate\Queue\SerializesModels;

class TaskChainProgress
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
     * The current task number.
     */
    public int $currentTask;

    /**
     * The total number of tasks.
     */
    public int $totalTasks;

    /**
     * The progress message.
     */
    public string $message;

    /**
     * The start timestamp.
     */
    public string $startedAt;

    /**
     * Create a new event instance.
     */
    public function __construct(array $tasks, string $chainId, int $currentTask, int $totalTasks, string $message, string $startedAt)
    {
        $this->tasks = $tasks;
        $this->chainId = $chainId;
        $this->currentTask = $currentTask;
        $this->totalTasks = $totalTasks;
        $this->message = $message;
        $this->startedAt = $startedAt;
    }

    /**
     * Get the progress percentage.
     */
    public function getPercentage(): float
    {
        if ($this->totalTasks === 0) {
            return 0.0;
        }

        return ($this->currentTask / $this->totalTasks) * 100;
    }

    /**
     * Get the progress percentage as an integer.
     */
    public function getPercentageInt(): int
    {
        return (int) round($this->getPercentage());
    }

    /**
     * Get the progress ratio (0.0 to 1.0).
     */
    public function getProgressRatio(): float
    {
        if ($this->totalTasks === 0) {
            return 0.0;
        }

        return $this->currentTask / $this->totalTasks;
    }

    /**
     * Get the remaining tasks.
     */
    public function getRemainingTasks(): int
    {
        return max(0, $this->totalTasks - $this->currentTask);
    }

    /**
     * Check if the chain is complete.
     */
    public function isComplete(): bool
    {
        return $this->currentTask >= $this->totalTasks;
    }

    /**
     * Check if the chain is in progress.
     */
    public function isInProgress(): bool
    {
        return $this->currentTask > 0 && $this->currentTask < $this->totalTasks;
    }

    /**
     * Check if the chain is just starting.
     */
    public function isStarting(): bool
    {
        return $this->currentTask === 1;
    }

    /**
     * Get a progress bar representation.
     */
    public function getProgressBar(int $width = 20): string
    {
        $filled = (int) round(($this->getPercentage() / 100) * $width);
        $empty = $width - $filled;

        return str_repeat('█', $filled).str_repeat('░', $empty);
    }

    /**
     * Get the current task name.
     */
    public function getCurrentTaskName(): string
    {
        if ($this->currentTask > 0 && isset($this->tasks[$this->currentTask - 1])) {
            return $this->tasks[$this->currentTask - 1]->getName();
        }

        return "Task {$this->currentTask}";
    }

    /**
     * Get progress details.
     */
    public function getProgressDetails(): array
    {
        return [
            'current_task' => $this->currentTask,
            'total_tasks' => $this->totalTasks,
            'percentage' => $this->getPercentage(),
            'percentage_int' => $this->getPercentageInt(),
            'progress_ratio' => $this->getProgressRatio(),
            'remaining_tasks' => $this->getRemainingTasks(),
            'is_complete' => $this->isComplete(),
            'is_in_progress' => $this->isInProgress(),
            'is_starting' => $this->isStarting(),
            'progress_bar' => $this->getProgressBar(),
            'current_task_name' => $this->getCurrentTaskName(),
            'message' => $this->message,
        ];
    }
}
