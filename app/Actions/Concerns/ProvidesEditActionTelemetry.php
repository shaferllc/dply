<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\ActionMetrics;
use App\Actions\Concerns\AsMetrics;
use App\Actions\Concerns\AsRetry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ProvidesEditActionTelemetry
{


    /**
     * Get module name from class namespace.
     *
     * Extracts module name from namespace for trace naming.
     *
     * @return string|null Module name or null
     */
    protected function getModuleName(): ?string
    {
        $namespace = static::class;
        if (preg_match('/App\\\\Modules\\\\([^\\\\]+)\\\\/', $namespace, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Configure retry base delay for this action.
     *
     * Used by AsRetry for exponential backoff calculation.
     * Delay increases exponentially: baseDelay * 2^attempt
     *
     * @return int Base delay in milliseconds
     */
    protected function getRetryBaseDelay(): int
    {
        return 1000; // Default: 1 second base delay
    }

    /**
     * Get current authenticated user.
     *
     * Convenience method for accessing the authenticated user.
     *
     * @return Authenticatable|null
     */
    protected function currentUser()
    {
        return Auth::user();
    }

    /**
     * Get current user ID.
     *
     * Convenience method for accessing the authenticated user's ID.
     */
    protected function currentUserId(): int|string|null
    {
        return Auth::id();
    }

    /**
     * Log an info message with action context.
     *
     * Automatically includes action class name and trace information.
     *
     * @param  string  $message  The log message
     * @param  array<string, mixed>  $context  Additional context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::info("[{$this->getActionName()}] {$message}", array_merge([
            'action' => static::class,
            'user_id' => $this->currentUserId(),
        ], $context));
    }

    /**
     * Log an error message with action context.
     *
     * Automatically includes action class name and trace information.
     *
     * @param  string  $message  The log message
     * @param  \Throwable|null  $exception  Optional exception
     * @param  array<string, mixed>  $context  Additional context
     */
    protected function logError(string $message, ?\Throwable $exception = null, array $context = []): void
    {
        Log::error("[{$this->getActionName()}] {$message}", array_merge([
            'action' => static::class,
            'user_id' => $this->currentUserId(),
            'exception' => $exception?->getMessage(),
        ], $context));
    }

    /**
     * Get action name for logging and tracing.
     *
     * Returns a human-readable name for the action.
     *
     * @return string Action name
     */
    protected function getActionName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Get performance metrics for this action.
     *
     * Retrieves metrics tracked by AsMetrics concern.
     * Note: This is an instance method that wraps ActionMetrics::getMetrics().
     * Use static::getMetrics() for the static method from AsMetrics trait.
     *
     * @return array<string, mixed> Performance metrics
     */
    public function getActionMetrics(): array
    {
        return ActionMetrics::getMetrics(static::class);
    }

    /**
     * Check if action has been called recently.
     *
     * Useful for rate limiting or duplicate prevention checks.
     *
     * @param  int  $seconds  Time window in seconds (default: 60)
     * @return bool Whether action was called recently
     */
    protected function wasCalledRecently(int $seconds = 60): bool
    {
        $metrics = $this->getActionMetrics();
        $lastCall = $metrics['last_call_at'] ?? null;

        if (! $lastCall) {
            return false;
        }

        return now()->diffInSeconds($lastCall) < $seconds;
    }

    /**
     * Get success rate for this action.
     *
     * Returns the success rate as a percentage (0-100).
     *
     * @return float Success rate percentage
     */
    protected function getSuccessRate(): float
    {
        $metrics = $this->getActionMetrics();
        $successRate = $metrics['success_rate'] ?? 0;

        return round($successRate * 100, 2);
    }

    /**
     * Check if action is performing well.
     *
     * Returns true if success rate is above threshold and average duration is acceptable.
     *
     * @param  float  $minSuccessRate  Minimum success rate (0-1, default: 0.95)
     * @param  int  $maxAvgDurationMs  Maximum average duration in milliseconds (default: 1000)
     * @return bool Whether action is performing well
     */
    protected function isPerformingWell(float $minSuccessRate = 0.95, int $maxAvgDurationMs = 1000): bool
    {
        $metrics = $this->getActionMetrics();
        $successRate = $metrics['success_rate'] ?? 0;
        $avgDuration = $metrics['avg_duration_ms'] ?? 0;

        return $successRate >= $minSuccessRate && $avgDuration <= $maxAvgDurationMs;
    }

    /**
     * Create a standardized success response array.
     *
     * Returns a consistent response structure for successful updates.
     * Note: This is a data structure helper, not an HTTP response.
     * Use AsResponse concern for HTTP responses.
     *
     * @param  mixed  $result  The updated resource
     * @param  string  $message  Success message
     * @param  array<string, mixed>  $metadata  Additional metadata
     * @return array<string, mixed> Standardized response data
     */
    protected function successResponseData(mixed $result, string $message = 'Updated successfully', array $metadata = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $result,
            'metadata' => array_merge([
                'action' => static::class,
                'updated_at' => now()->toIso8601String(),
                'updated_by' => $this->currentUserId(),
            ], $metadata),
        ];
    }

    /**
     * Create a standardized error response array.
     *
     * Returns a consistent response structure for errors.
     * Note: This is a data structure helper, not an HTTP response.
     * Use AsResponse concern for HTTP responses.
     *
     * @param  string  $message  Error message
     * @param  array<string, mixed>  $errors  Validation errors or additional error details
     * @param  int  $code  Error code
     * @return array<string, mixed> Standardized error response data
     */
    protected function errorResponseData(string $message, array $errors = [], int $code = 400): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'code' => $code,
            'metadata' => [
                'action' => static::class,
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Get request ID for tracing.
     *
     * Retrieves or generates a request ID for distributed tracing.
     *
     * @return string Request ID
     */
    protected function getRequestId(): string
    {
        return request()->header('X-Request-ID') ?? (string) Str::uuid();
    }
}
