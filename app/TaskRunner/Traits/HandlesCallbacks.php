<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Traits;

use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\CallbackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * HandlesCallbacks trait migrated from the Tasks module.
 * Provides comprehensive callback handling functionality for tasks.
 *
 * @codeCoverageIgnore Handled by DeploySiteTest and TaskWithCallbackTest.
 */
trait HandlesCallbacks
{
    protected ?string $callbackUrl = null;

    protected ?int $callbackTimeout = null;

    protected ?int $callbackMaxAttempts = null;

    protected ?int $callbackDelay = null;

    protected ?int $callbackBackoffMultiplier = null;

    protected ?bool $callbacksEnabled = null;

    /**
     * Configure callback settings.
     *
     * @param  array<string, mixed>  $config
     */
    public function setCallbackConfig(array $config): self
    {
        if (array_key_exists('url', $config)) {
            $this->callbackUrl = is_string($config['url']) ? $config['url'] : null;
        }

        if (array_key_exists('timeout', $config)) {
            $this->callbackTimeout = is_int($config['timeout']) ? $config['timeout'] : null;
        }

        if (array_key_exists('max_attempts', $config)) {
            $this->callbackMaxAttempts = is_int($config['max_attempts']) ? $config['max_attempts'] : null;
        }

        if (array_key_exists('delay', $config)) {
            $this->callbackDelay = is_int($config['delay']) ? $config['delay'] : null;
        }

        if (array_key_exists('backoff_multiplier', $config)) {
            $this->callbackBackoffMultiplier = is_int($config['backoff_multiplier']) ? $config['backoff_multiplier'] : null;
        }

        if (array_key_exists('enabled', $config)) {
            $this->callbacksEnabled = is_bool($config['enabled']) ? $config['enabled'] : null;
        }

        return $this;
    }

    /**
     * Handle a callback for the given task.
     */
    public function handleCallback(Task $task, Request $request, CallbackType $callbackType): void
    {
        match ($callbackType) {
            CallbackType::Timeout => $this->onTimeout($task, $request),
            CallbackType::Failed => $this->onFailed($task, $request),
            CallbackType::Finished => $this->onFinished($task, $request),
            CallbackType::Custom => $this->onCustomCallback($task, $request),
            CallbackType::Started => $this->onStarted($task, $request),
            CallbackType::Progress => $this->onProgress($task, $request),
            CallbackType::Cancelled => $this->onCancelled($task, $request),
            CallbackType::Paused => $this->onPaused($task, $request),
            CallbackType::Resumed => $this->onResumed($task, $request),
        };

        $this->afterCallback($task, $request, $callbackType);
    }

    /**
     * Get the callback URL for this task.
     */
    public function getCallbackUrl(): ?string
    {
        return $this->callbackUrl ?? null;
    }

    /**
     * Get the callback data to send with the request.
     */
    /** @return array<string, mixed> */
    public function getCallbackData(): array
    {
        return [
            'task_id' => $this->taskModel?->id,
            'task_name' => $this->taskModel?->name,
            'status' => $this->taskModel?->status?->value,
            'exit_code' => $this->taskModel?->exit_code,
            'duration' => $this->taskModel?->getDuration(),
            'output' => $this->taskModel?->getOutput(),
            'timestamp' => now()->toISOString(),
            'callback_type' => 'task_update',
        ];
    }

    /** @return array<string, mixed> */
    public function getCallbackHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'TaskRunner/1.0',
            'X-Task-ID' => $this->taskModel?->id,
            'X-Callback-Type' => 'task_update',
        ];
    }

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
     * Get the callback retry configuration.
     */
    /** @return array<string, mixed> */
    public function getCallbackRetryConfig(): array
    {
        return [
            'max_attempts' => $this->callbackMaxAttempts ?? 3,
            'delay' => $this->callbackDelay ?? 5,
            'backoff_multiplier' => $this->callbackBackoffMultiplier ?? 2,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validateCallbackData(array $data): bool
    {
        return ! empty($data) && isset($data['task_id']);
    }

    /**
     * @param array<string, mixed> $additionalData
     */
    public function sendCallback(CallbackType $type, array $additionalData = []): bool
    {
        if (! $this->isCallbacksEnabled() || ! $this->getCallbackUrl()) {
            return false;
        }

        $callbackService = app(CallbackService::class);

        return $callbackService->send($this, $type, $additionalData);
    }

    /**
     * Send a callback when task starts.
     */
    public function sendStartedCallback(): bool
    {
        return $this->sendCallback(CallbackType::Started, [
            'event' => 'task_started',
            'started_at' => now()->toISOString(),
        ]);
    }

    /**
     * Send a callback when task finishes successfully.
     */
    public function sendFinishedCallback(): bool
    {
        return $this->sendCallback(CallbackType::Finished, [
            'event' => 'task_finished',
            'completed_at' => now()->toISOString(),
            'success' => true,
        ]);
    }

    /**
     * Send a callback when task fails.
     */
    public function sendFailedCallback(?string $error = null): bool
    {
        return $this->sendCallback(CallbackType::Failed, [
            'event' => 'task_failed',
            'failed_at' => now()->toISOString(),
            'success' => false,
            'error' => $error,
        ]);
    }

    /**
     * Send a callback when task times out.
     */
    public function sendTimeoutCallback(): bool
    {
        return $this->sendCallback(CallbackType::Timeout, [
            'event' => 'task_timeout',
            'timed_out_at' => now()->toISOString(),
            'timeout_duration' => $this->taskModel?->timeout,
        ]);
    }

    /**
     * Send a progress callback with custom data.
      * @param array<string, mixed> $progressData
     */
    public function sendProgressCallback(array $progressData): bool
    {
        return $this->sendCallback(CallbackType::Progress, array_merge([
            'event' => 'task_progress',
            'progress_at' => now()->toISOString(),
        ], $progressData));
    }

    /**
     * Handle timeout callback.
     */
    protected function onTimeout(Task $task, Request $request): void
    {
        $task->update([
            'status' => TaskStatus::Timeout,
            'exit_code' => 124,
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle failed callback.
     */
    protected function onFailed(Task $task, Request $request): void
    {
        $exitCode = $request->input('exit_code', 1);

        $task->update([
            'status' => TaskStatus::Failed,
            'exit_code' => $exitCode,
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle finished callback.
     */
    protected function onFinished(Task $task, Request $request): void
    {
        $exitCode = $request->input('exit_code', 0);

        $task->update([
            'status' => TaskStatus::Finished,
            'exit_code' => $exitCode,
            'completed_at' => now(),
        ]);
    }

    /**
     * Handle custom callback.
     */
    protected function onCustomCallback(Task $task, Request $request): void
    {
        $data = $request->all();
        $this->sendCallback(CallbackType::Custom, $data);
    }

    protected function onStarted(Task $task, Request $request): void
    {
        // Lifecycle callbacks are handled via sendStartedCallback(); no model mutation required here.
    }

    protected function onProgress(Task $task, Request $request): void
    {
        // Progress payloads are forwarded by sendProgressCallback(); no model mutation required here.
    }

    protected function onCancelled(Task $task, Request $request): void
    {
        $task->update([
            'status' => TaskStatus::Cancelled,
            'completed_at' => now(),
        ]);
    }

    protected function onPaused(Task $task, Request $request): void
    {
        // Pause/resume state is tracked by the remote runner; no model mutation required here.
    }

    protected function onResumed(Task $task, Request $request): void
    {
        // Pause/resume state is tracked by the remote runner; no model mutation required here.
    }

    /**
     * Handle after callback processing.
     */
    protected function afterCallback(Task $task, Request $request, CallbackType $callbackType): void
    {
        // Log callback for debugging
        Log::info('Task callback processed', [
            'task_id' => $task->id,
            'callback_type' => $callbackType->value,
            'url' => $this->getCallbackUrl(),
        ]);
    }
}
