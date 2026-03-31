<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Events;

use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\Task;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskProgress
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
     * The current step number.
     */
    public int $currentStep;

    /**
     * The total number of steps.
     */
    public int $totalSteps;

    /**
     * The current step name.
     */
    public string $stepName;

    /**
     * The progress percentage.
     */
    public float $percentage;

    /**
     * The progress timestamp.
     */
    public string $timestamp;

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
        int $currentStep,
        int $totalSteps,
        string $stepName,
        array $context = []
    ) {
        $this->task = $task;
        $this->pendingTask = $pendingTask;
        $this->currentStep = $currentStep;
        $this->totalSteps = $totalSteps;
        $this->stepName = $stepName;
        $this->percentage = $totalSteps > 0 ? ($currentStep / $totalSteps) * 100 : 0;
        $this->timestamp = now()->toISOString();
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
     * Get the current step number.
     */
    public function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    /**
     * Get the total number of steps.
     */
    public function getTotalSteps(): int
    {
        return $this->totalSteps;
    }

    /**
     * Get the current step name.
     */
    public function getStepName(): string
    {
        return $this->stepName;
    }

    /**
     * Get the progress percentage.
     */
    public function getPercentage(): float
    {
        return $this->percentage;
    }

    /**
     * Get the progress percentage as an integer.
     */
    public function getPercentageInt(): int
    {
        return (int) round($this->percentage);
    }

    /**
     * Check if the task is complete.
     */
    public function isComplete(): bool
    {
        return $this->currentStep >= $this->totalSteps;
    }

    /**
     * Check if the task is in progress.
     */
    public function isInProgress(): bool
    {
        return $this->currentStep > 0 && $this->currentStep < $this->totalSteps;
    }

    /**
     * Check if the task is just starting.
     */
    public function isStarting(): bool
    {
        return $this->currentStep === 1;
    }

    /**
     * Get the remaining steps.
     */
    public function getRemainingSteps(): int
    {
        return max(0, $this->totalSteps - $this->currentStep);
    }

    /**
     * Get the progress ratio (0.0 to 1.0).
     */
    public function getProgressRatio(): float
    {
        return $this->totalSteps > 0 ? $this->currentStep / $this->totalSteps : 0;
    }

    /**
     * Get a progress bar representation.
     */
    public function getProgressBar(int $width = 20): string
    {
        $filled = (int) round(($this->percentage / 100) * $width);
        $empty = $width - $filled;

        return str_repeat('█', $filled).str_repeat('░', $empty);
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
        return $this->pendingTask->getConnection();
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
     * Get progress details.
     */
    public function getProgressDetails(): array
    {
        return [
            'current_step' => $this->currentStep,
            'total_steps' => $this->totalSteps,
            'step_name' => $this->stepName,
            'percentage' => $this->percentage,
            'percentage_int' => $this->getPercentageInt(),
            'progress_ratio' => $this->getProgressRatio(),
            'remaining_steps' => $this->getRemainingSteps(),
            'is_complete' => $this->isComplete(),
            'is_in_progress' => $this->isInProgress(),
            'is_starting' => $this->isStarting(),
            'progress_bar' => $this->getProgressBar(),
            'timestamp' => $this->timestamp,
        ];
    }
}
