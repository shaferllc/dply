<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

use App\Modules\TaskRunner\Contracts\StreamingLoggerInterface;
use App\Modules\TaskRunner\Events\TaskProgress;
use App\Modules\TaskRunner\PendingTask;

trait HasProgressTracking
{
    /**
     * The current step number.
     */
    protected int $currentStep = 0;

    /**
     * The total number of steps.
     */
    protected int $totalSteps = 0;

    /**
     * The step descriptions.
     */
    protected array $stepDescriptions = [];

    /**
     * The streaming logger instance.
     */
    protected ?StreamingLoggerInterface $progressLogger = null;

    /**
     * Initialize progress tracking.
     */
    protected function initializeProgress(int $totalSteps, array $stepDescriptions = []): void
    {
        $this->totalSteps = $totalSteps;
        $this->stepDescriptions = $stepDescriptions;
        $this->currentStep = 0;
        $this->progressLogger = app(StreamingLoggerInterface::class);

        $this->streamProgress(0, $totalSteps, 'Task initialized');
    }

    /**
     * Move to the next step.
     */
    protected function nextStep(?string $description = null): void
    {
        $this->currentStep++;

        if ($this->currentStep <= $this->totalSteps) {
            $message = $description ?? ($this->stepDescriptions[$this->currentStep - 1] ?? "Step {$this->currentStep}");
            $this->streamProgress($this->currentStep, $this->totalSteps, $message);
        }
    }

    /**
     * Set a specific step.
     */
    protected function setStep(int $step, ?string $description = null): void
    {
        $this->currentStep = $step;

        if ($step <= $this->totalSteps) {
            $message = $description ?? ($this->stepDescriptions[$step - 1] ?? "Step {$step}");
            $this->streamProgress($step, $this->totalSteps, $message);
        }
    }

    /**
     * Complete the task.
     */
    protected function completeTask(string $message = 'Task completed successfully'): void
    {
        $this->streamProgress($this->totalSteps, $this->totalSteps, $message);
    }

    /**
     * Stream progress update.
     */
    protected function streamProgress(int $current, int $total, string $message = ''): void
    {
        if ($this->progressLogger) {
            $this->progressLogger->streamProgress($current, $total, $message, [
                'task_id' => $this->getTaskId(),
                'step' => $current,
                'total_steps' => $total,
                'step_description' => $message,
            ]);
        }

        // Dispatch progress event
        if ($current > 0 && $total > 0) {
            $pendingTask = $this->getPendingTask();
            if ($pendingTask) {
                event(new TaskProgress(
                    $this,
                    $pendingTask,
                    $current,
                    $total,
                    $message
                ));
            }
        }
    }

    /**
     * Get the current progress percentage.
     */
    protected function getProgressPercentage(): float
    {
        if ($this->totalSteps === 0) {
            return 0.0;
        }

        return round(($this->currentStep / $this->totalSteps) * 100, 2);
    }

    /**
     * Get the current step.
     */
    protected function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    /**
     * Get the total steps.
     */
    protected function getTotalSteps(): int
    {
        return $this->totalSteps;
    }

    /**
     * Check if the task is completed.
     */
    protected function isCompleted(): bool
    {
        return $this->currentStep >= $this->totalSteps;
    }

    /**
     * Get the task ID for progress tracking.
     */
    protected function getTaskId(): string
    {
        // Override this method in your task class to provide a unique task ID
        return static::class.'-'.uniqid();
    }

    /**
     * Get the pending task instance.
     */
    protected function getPendingTask(): ?PendingTask
    {
        // This should be overridden in the task class to return the actual pending task
        // For now, return null to avoid errors
        return null;
    }

    /**
     * Add a custom progress step.
     */
    protected function addProgressStep(string $description): void
    {
        $this->stepDescriptions[] = $description;
        $this->totalSteps = count($this->stepDescriptions);
    }

    /**
     * Update step description.
     */
    protected function updateStepDescription(int $step, string $description): void
    {
        if (isset($this->stepDescriptions[$step - 1])) {
            $this->stepDescriptions[$step - 1] = $description;
        }
    }

    /**
     * Get step description.
     */
    protected function getStepDescription(int $step): string
    {
        return $this->stepDescriptions[$step - 1] ?? "Step {$step}";
    }

    /**
     * Reset progress tracking.
     */
    protected function resetProgress(): void
    {
        $this->currentStep = 0;
        $this->streamProgress(0, $this->totalSteps, 'Progress reset');
    }
}
