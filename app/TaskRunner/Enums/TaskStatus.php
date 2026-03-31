<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case Cancelled = 'cancelled';
    case UploadFailed = 'upload_failed';
    case ConnectionFailed = 'connection_failed';

    /**
     * Get a human-readable description of the status.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Pending => 'Task is waiting to be executed',
            self::Running => 'Task is currently executing',
            self::Finished => 'Task completed successfully',
            self::Failed => 'Task failed during execution',
            self::Timeout => 'Task timed out',
            self::Cancelled => 'Task was cancelled',
            self::UploadFailed => 'Task file upload failed',
            self::ConnectionFailed => 'Task connection failed',
        };
    }

    /**
     * Get the CSS class for styling.
     */
    public function getCssClass(): string
    {
        return match ($this) {
            self::Pending => 'status-pending',
            self::Running => 'status-running',
            self::Finished => 'status-finished',
            self::Failed => 'status-failed',
            self::Timeout => 'status-timeout',
            self::Cancelled => 'status-cancelled',
            self::UploadFailed => 'status-upload-failed',
            self::ConnectionFailed => 'status-connection-failed',
        };
    }

    /**
     * Get the icon for the status.
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => '⏳',
            self::Running => '🔄',
            self::Finished => '✅',
            self::Failed => '❌',
            self::Timeout => '⏰',
            self::Cancelled => '🚫',
            self::UploadFailed => '📤',
            self::ConnectionFailed => '🔌',
        };
    }

    /**
     * Check if the status indicates the task is complete.
     */
    public function isComplete(): bool
    {
        return in_array($this, [
            self::Finished,
            self::Failed,
            self::Timeout,
            self::Cancelled,
            self::UploadFailed,
            self::ConnectionFailed,
        ]);
    }

    /**
     * Check if the status indicates the task is active.
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Running,
        ]);
    }

    /**
     * Check if the status indicates the task was successful.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Finished;
    }

    /**
     * Check if the status indicates the task failed.
     */
    public function isFailed(): bool
    {
        return in_array($this, [
            self::Failed,
            self::Timeout,
            self::UploadFailed,
            self::ConnectionFailed,
        ]);
    }

    /**
     * Get all statuses that indicate completion.
     */
    public static function getCompletedStatuses(): array
    {
        return [
            self::Finished,
            self::Failed,
            self::Timeout,
            self::Cancelled,
            self::UploadFailed,
            self::ConnectionFailed,
        ];
    }

    /**
     * Get all statuses that indicate failure.
     */
    public static function getFailedStatuses(): array
    {
        return [
            self::Failed,
            self::Timeout,
            self::UploadFailed,
            self::ConnectionFailed,
        ];
    }
}
