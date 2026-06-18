<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Enums;

/**
 * CallbackType enum for task callback handling.
 * Defines the types of callbacks that can be triggered for tasks.
 */
enum CallbackType: string
{
    case Custom = 'custom';
    case Timeout = 'timeout';
    case Failed = 'failed';
    case Finished = 'finished';
    case Started = 'started';
    case Progress = 'progress';
    case Cancelled = 'cancelled';
    case Paused = 'paused';
    case Resumed = 'resumed';

    /**
     * Get a human-readable description of the callback type.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Custom => 'Custom callback',
            self::Timeout => 'Task timed out',
            self::Failed => 'Task failed',
            self::Finished => 'Task finished successfully',
            self::Started => 'Task started execution',
            self::Progress => 'Task progress update',
            self::Cancelled => 'Task was cancelled',
            self::Paused => 'Task was paused',
            self::Resumed => 'Task was resumed',
        };
    }

    /**
     * Get the HTTP method for the callback.
     */
    public function getHttpMethod(): string
    {
        return 'POST';
    }

    /**
     * Check if this is a completion callback.
     */
    public function isCompletion(): bool
    {
        return in_array($this, [
            self::Finished,
            self::Failed,
            self::Timeout,
            self::Cancelled,
        ]);
    }

    /**
     * Check if this is a failure callback.
     */
    public function isFailure(): bool
    {
        return in_array($this, [
            self::Failed,
            self::Timeout,
        ]);
    }

    /**
     * Check if this is a success callback.
     */
    public function isSuccess(): bool
    {
        return $this === self::Finished;
    }

    /**
     * Check if this is a lifecycle callback.
     */
    public function isLifecycle(): bool
    {
        return in_array($this, [
            self::Started,
            self::Paused,
            self::Resumed,
            self::Cancelled,
        ]);
    }

    /**
     * Get the priority level for this callback type.
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::Failed, self::Timeout => 1, // High priority
            self::Finished, self::Cancelled => 2, // Medium priority
            self::Started, self::Progress => 3, // Normal priority
            self::Paused, self::Resumed, self::Custom => 4, // Low priority
        };
    }
}
