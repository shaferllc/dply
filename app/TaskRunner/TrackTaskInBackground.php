<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Services\BackgroundTaskTracker;
use App\Modules\TaskRunner\Services\CallbackService;
use App\Modules\TaskRunner\Traits\HandlesCallbacks;
use Illuminate\Support\Str;

/**
 * TrackTaskInBackground task migrated from the Tasks module.
 * Tracks task execution in the background with comprehensive callback support.
 */
class TrackTaskInBackground extends Task implements HasCallbacks
{
    use HandlesCallbacks;

    protected ?string $callbackUrl = null;

    // Callback configuration properties
    protected ?int $callbackTimeout = null;

    protected ?int $callbackMaxAttempts = null;

    protected ?int $callbackDelay = null;

    protected ?int $callbackBackoffMultiplier = null;

    protected ?bool $callbacksEnabled = null;

    public string $eof;

    public string $view = 'task-runner::track-task-in-background';

    /**
     * Create a new TrackTaskInBackground instance.
     */
    public function __construct(
        public Task $actualTask,
        public string $finishedUrl,
        public string $failedUrl,
        public string $timeoutUrl,
    ) {
        $this->eof = 'DPLY-TASK-RUNNER-'.strtoupper(Str::random(32));

        // Configure callbacks for background tracking
        $this->configureCallbacks();

    }

    /**
     * Configure callbacks for background tracking.
     */
    protected function configureCallbacks(): void
    {
        // Set callback URLs for different events
        $this->callbackUrl = $this->finishedUrl;

        // Configure callback settings using properties
        $this->callbackTimeout = 30;
        $this->callbackMaxAttempts = 3;
        $this->callbackDelay = 5;
        $this->callbackBackoffMultiplier = 2;
        $this->callbacksEnabled = true;
    }

    /**
     * Get the timeout for this task.
     */
    public function getTimeout(): int
    {
        $timeout = (int) ($this->actualTask->getTimeout() ?? 0) + 30;

        return min($timeout, 3600);
    }

    /**
     * Handle the task execution with background tracking.
     */
    public function handle(): void
    {
        // If we're in fake mode, just execute the actual task directly
        if (static::shouldDisableBackgroundTracking()) {
            $this->executeActualTask();

            return;
        }

        // If callbacks are disabled, execute without background tracking
        if (! $this->isCallbacksEnabled()) {
            // Create a simple task model for tracking
            $taskModel = $this->createTaskModel();
            $this->actualTask->setTaskModel($taskModel);
            $this->setTaskModel($taskModel);

            // Execute the actual task
            $this->executeActualTask();

            return;
        }

        // Original handle logic for real instances with background tracking
        $tracker = app(BackgroundTaskTracker::class);

        // Create or get the task model
        $taskModel = $this->createTaskModel();

        // Set the task model on both the actual task and the tracking task
        $this->actualTask->setTaskModel($taskModel);
        $this->setTaskModel($taskModel);

        // Start background tracking
        $tracker->startTracking($taskModel, $this);

        // Execute the actual task
        $this->executeActualTask();
    }

    /**
     * Create a task model for tracking.
     */
    protected function createTaskModel(): Models\Task
    {
        // Prepare options including callback URLs and actual task class
        $options = array_merge($this->actualTask->getOptions(), [
            'finished_url' => $this->finishedUrl,
            'failed_url' => $this->failedUrl,
            'timeout_url' => $this->timeoutUrl,
            'actual_task_class' => get_class($this->actualTask),
        ]);

        return Models\Task::create([
            'name' => $this->actualTask->getName(),
            'action' => $this->actualTask->getAction(),
            'status' => TaskStatus::Pending,
            'script' => $this->actualTask->getScript(),
            'options' => json_encode($options),
            'timeout' => $this->getTimeout(),
            'user' => $this->actualTask->getUser(),
            'created_by' => null,
        ]);
    }

    /**
     * Execute the actual task with proper error handling and callbacks.
     */
    protected function executeActualTask(): void
    {
        try {
            // Execute the actual task
            $this->actualTask->handle();

            // Task completed successfully
            $this->handleTaskSuccess();

        } catch (\Exception $e) {
            // Task failed
            $this->handleTaskFailure($e);
        }
    }

    /**
     * Handle successful task completion.
     */
    protected function handleTaskSuccess(): void
    {
        $taskModel = $this->actualTask->getTaskModel();

        if ($taskModel) {
            // Update task model status
            $taskModel->update([
                'status' => TaskStatus::Finished,
                'completed_at' => now(),
            ]);

            // Use background tracking if callbacks are enabled and not in fake mode
            if ($this->isCallbacksEnabled() && ! static::shouldDisableBackgroundTracking()) {
                $tracker = app(BackgroundTaskTracker::class);
                $tracker->handleTaskCompletion($taskModel, $this);
            }
        }

        // Send success callback if enabled
        if ($this->isCallbacksEnabled()) {
            $this->sendCallback(CallbackType::Finished, [
                'event' => 'task_completed',
                'success' => true,
                'completed_at' => now()->toISOString(),
            ]);
        }
    }

    /**
     * Handle task failure.
     */
    protected function handleTaskFailure(\Exception $e): void
    {
        $taskModel = $this->actualTask->getTaskModel();

        if ($taskModel) {
            // Update task model status
            $taskModel->update([
                'status' => TaskStatus::Failed,
                'output' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Use background tracking if callbacks are enabled and not in fake mode
            if ($this->isCallbacksEnabled() && ! static::shouldDisableBackgroundTracking()) {
                $tracker = app(BackgroundTaskTracker::class);
                $tracker->handleTaskFailure($taskModel, $this, $e->getMessage());
            }
        }

        // Send failure callback if enabled
        if ($this->isCallbacksEnabled()) {
            $this->sendCallback(CallbackType::Failed, [
                'event' => 'task_failed',
                'success' => false,
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString(),
            ]);
        }

        // Re-throw the exception
        throw $e;
    }

    /**
     * Get the callback URL for this task.
     */
    public function getCallbackUrl(?CallbackType $type = null): ?string
    {
        $type = $type ?? CallbackType::Finished;

        return match ($type) {
            CallbackType::Finished => $this->finishedUrl,
            CallbackType::Failed => $this->failedUrl,
            CallbackType::Timeout => $this->timeoutUrl,
            default => $this->finishedUrl,
        };
    }

    /**
     * Get the callback data to send with the request.
     */
    public function getCallbackData(): array
    {
        $taskModel = $this->actualTask->getTaskModel();

        return [
            'task_id' => $taskModel?->id,
            'task_name' => $this->actualTask->getName(),
            'status' => $taskModel?->status?->value,
            'exit_code' => $taskModel?->exit_code,
            'duration' => $taskModel?->getDuration(),
            'output' => $taskModel?->getOutput(),
            'timestamp' => now()->toISOString(),
            'callback_type' => 'background_task_update',
            'actual_task_class' => get_class($this->actualTask),
        ];
    }

    /**
     * Get the callback headers to send with the request.
     */
    public function getCallbackHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'TaskRunner/2.0',
            'X-Task-ID' => $this->actualTask->getTaskModel()?->id,
            'X-Callback-Type' => 'background_task_update',
            'X-Actual-Task-Class' => get_class($this->actualTask),
        ];
    }

    /**
     * Get the callback timeout in seconds.
     */
    public function getCallbackTimeout(): int
    {
        return $this->callbackTimeout ?? 30;
    }

    /**
     * Check if callbacks are enabled for this task.
     */
    public function isCallbacksEnabled(): bool
    {
        return $this->callbacksEnabled ?? true;
    }

    /**
     * Disable callbacks for testing purposes.
     */
    public function disableCallbacks(): self
    {
        $this->callbacksEnabled = false;

        return $this;
    }

    /**
     * Enable callbacks (default state).
     */
    public function enableCallbacks(): self
    {
        $this->callbacksEnabled = true;

        return $this;
    }

    /**
     * Get the callback retry configuration.
     */
    public function getCallbackRetryConfig(): array
    {
        return [
            'max_attempts' => $this->callbackMaxAttempts ?? 3,
            'delay' => $this->callbackDelay ?? 5,
            'backoff_multiplier' => $this->callbackBackoffMultiplier ?? 2,
        ];
    }

    /**
     * The background wrapper script intentionally uses shell variable expansion and command
     * substitution while still honoring script size and forbidden command checks.
     */
    protected function validateScript(string $script): void
    {
        $errors = [];

        if (strlen($script) > 1024 * 1024) {
            $errors['script_size'] = 'Script is too large (max 1024KB).';
        }

        $forbiddenCommands = config('task-runner.security.forbidden_commands', []);
        foreach ($forbiddenCommands as $command) {
            if (stripos($script, $command) !== false) {
                $errors['forbidden_command'] = "Script contains forbidden command: {$command}";
                break;
            }
        }

        if (! empty($errors)) {
            throw \App\Modules\TaskRunner\Exceptions\TaskValidationException::withErrors($errors);
        }
    }

    /**
     * Validate callback data before sending.
     */
    public function validateCallbackData(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // When background tracking is disabled, we don't require task_id
        if (static::shouldDisableBackgroundTracking()) {
            return true;
        }

        // When background tracking is enabled, we require task_id
        return isset($data['task_id']);
    }

    /**
     * Send a callback to the configured URL.
     */
    public function sendCallback(CallbackType $type, array $additionalData = []): bool
    {
        if (! $this->isCallbacksEnabled() || ! $this->getCallbackUrl($type)) {
            return false;
        }

        $callbackService = app(CallbackService::class);

        return $callbackService->send($this, $type, $additionalData);
    }

    /**
     * Get task information for monitoring and management.
     */
    public function getTaskInfo(): array
    {
        $taskModel = $this->actualTask->getTaskModel();

        return [
            'tracking_class' => static::class,
            'actual_task_class' => get_class($this->actualTask),
            'task_id' => $taskModel?->id,
            'task_name' => $this->actualTask->getName(),
            'status' => $taskModel?->status?->value,
            'started_at' => $taskModel?->started_at?->toISOString(),
            'completed_at' => $taskModel?->completed_at?->toISOString(),
            'duration' => $taskModel?->getDuration(),
            'callbacks_enabled' => $this->isCallbacksEnabled(),
            'callback_url' => $this->getCallbackUrl(),
        ];
    }
}
