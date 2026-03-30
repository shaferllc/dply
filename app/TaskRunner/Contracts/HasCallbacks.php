<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Contracts;

use App\Modules\TaskRunner\Enums\CallbackType;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Http\Request;

/**
 * HasCallbacks contract for tasks that support callback handling.
 * Provides comprehensive callback functionality for sending status updates
 * and data back to the home server.
 */
interface HasCallbacks
{
    /**
     * Handle a callback for the given task.
     */
    public function handleCallback(Task $task, Request $request, CallbackType $type): void;

    /**
     * Get the callback URL for this task.
     */
    public function getCallbackUrl(): ?string;

    /**
     * Get the callback data to send with the request.
     */
    public function getCallbackData(): array;

    /**
     * Get the callback headers to send with the request.
     */
    public function getCallbackHeaders(): array;

    /**
     * Get the callback timeout in seconds.
     */
    public function getCallbackTimeout(): int;

    /**
     * Check if callbacks are enabled for this task.
     */
    public function isCallbacksEnabled(): bool;

    /**
     * Get the callback retry configuration.
     */
    public function getCallbackRetryConfig(): array;

    /**
     * Validate callback data before sending.
     */
    public function validateCallbackData(array $data): bool;
}
