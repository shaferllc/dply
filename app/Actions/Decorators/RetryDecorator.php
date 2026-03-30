<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Retry Decorator
 *
 * Automatically retries failed actions with exponential backoff.
 * This decorator intercepts handle() calls and retries on failure
 * with configurable retry count, delay, and exception filtering.
 *
 * Features:
 * - Automatic retry on failure
 * - Exponential backoff delay
 * - Configurable max retries
 * - Exception-based retry filtering
 * - Custom retry delay
 * - Retry metadata in results
 *
 * How it works:
 * 1. When an action uses AsRetry, RetryDesignPattern recognizes it
 * 2. ActionManager wraps the action with RetryDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action in a try-catch
 *    - On failure, checks if should retry
 *    - Calculates exponential backoff delay
 *    - Retries up to max retries
 *    - Throws exception if all retries fail
 *    - Adds retry metadata to result
 *
 * Retry Metadata:
 * The result will include a `_retry` property with:
 * - `attempts`: Number of attempts made
 * - `max_retries`: Maximum retry count
 * - `retried`: Whether retries were attempted
 */
class RetryDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with retry logic.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Throwable If all retries are exhausted
     */
    public function handle(...$arguments)
    {
        $maxRetries = $this->getMaxRetries();
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                $result = $this->action->handle(...$arguments);

                // Add retry metadata to result
                if (is_object($result)) {
                    $result->_retry = [
                        'attempts' => $attempt + 1,
                        'max_retries' => $maxRetries,
                        'retried' => $attempt > 0,
                    ];
                }

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                // Check if we should retry
                if ($attempt > $maxRetries || ! $this->shouldRetry($e)) {
                    throw $e;
                }

                // Calculate and apply delay
                $delay = $this->calculateRetryDelay($attempt);
                usleep($delay * 1000); // Convert milliseconds to microseconds
            }
        }

        // This should never be reached, but just in case
        throw $lastException ?? new \RuntimeException('Retry failed without exception');
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Get maximum number of retries.
     */
    protected function getMaxRetries(): int
    {
        if ($this->hasMethod('getMaxRetries')) {
            return (int) $this->callMethod('getMaxRetries');
        }

        if ($this->hasProperty('maxRetries')) {
            return (int) $this->getProperty('maxRetries');
        }

        return 3; // Default: 3 retries
    }

    /**
     * Get base retry delay in milliseconds.
     */
    protected function getRetryDelay(): int
    {
        if ($this->hasMethod('getRetryDelay')) {
            return (int) $this->callMethod('getRetryDelay');
        }

        if ($this->hasProperty('retryDelay')) {
            return (int) $this->getProperty('retryDelay');
        }

        return 1000; // Default: 1 second
    }

    /**
     * Determine if the exception should trigger a retry.
     */
    protected function shouldRetry(\Throwable $exception): bool
    {
        if ($this->hasMethod('shouldRetry')) {
            return (bool) $this->callMethod('shouldRetry', [$exception]);
        }

        // Default: retry on all exceptions except fatal errors
        return ! ($exception instanceof \Error);
    }

    /**
     * Calculate retry delay with exponential backoff.
     *
     * @param  int  $attempt  Current attempt number (1-based)
     * @return int Delay in milliseconds
     */
    protected function calculateRetryDelay(int $attempt): int
    {
        $baseDelay = $this->getRetryDelay();

        // Exponential backoff: delay * 2^(attempt - 1)
        // Attempt 1: baseDelay * 2^0 = baseDelay
        // Attempt 2: baseDelay * 2^1 = baseDelay * 2
        // Attempt 3: baseDelay * 2^2 = baseDelay * 4
        return (int) ($baseDelay * pow(2, $attempt - 1));
    }
}
