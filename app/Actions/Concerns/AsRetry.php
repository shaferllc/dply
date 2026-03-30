<?php

namespace App\Actions\Concerns;

/**
 * Automatically retries failed actions with exponential backoff.
 *
 * Provides automatic retry capabilities for actions, allowing them to
 * automatically retry on failure with exponential backoff delays.
 * Retry behavior is configurable per action.
 *
 * How it works:
 * - RetryDesignPattern recognizes actions using AsRetry
 * - ActionManager wraps the action with RetryDecorator
 * - When handle() is called, the decorator:
 *    - Executes the action in a try-catch block
 *    - On failure, checks if exception should be retried
 *    - Calculates exponential backoff delay
 *    - Retries up to max retries
 *    - Throws exception if all retries are exhausted
 *    - Adds retry metadata to result
 *
 * Benefits:
 * - Automatic retry on transient failures
 * - Exponential backoff (prevents overwhelming services)
 * - Configurable retry count
 * - Exception-based retry filtering
 * - Custom retry delays
 * - Retry metadata in results
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * RetryDecorator, which automatically wraps actions and handles retry logic.
 * This follows the same pattern as AsTimeout, AsThrottle, and other
 * decorator-based concerns.
 *
 * Retry Metadata:
 * The result will include a `_retry` property with:
 * - `attempts`: Number of attempts made (including initial attempt)
 * - `max_retries`: Maximum retry count configured
 * - `retried`: Whether retries were attempted (true if attempts > 1)
 *
 * Exponential Backoff:
 * Retry delays increase exponentially with each attempt:
 * - Attempt 1: baseDelay * 2^0 = baseDelay (e.g., 1000ms)
 * - Attempt 2: baseDelay * 2^1 = baseDelay * 2 (e.g., 2000ms)
 * - Attempt 3: baseDelay * 2^2 = baseDelay * 4 (e.g., 4000ms)
 * - Attempt 4: baseDelay * 2^3 = baseDelay * 8 (e.g., 8000ms)
 *
 * @example
 * // Basic usage - automatic retry on failure:
 * class SendEmail extends Actions
 * {
 *     use AsRetry;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         Mail::to($user)->send(new NotificationMail($message));
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 3; // Retry up to 3 times
 *     }
 * }
 *
 * // Usage:
 * $result = SendEmail::run($user, 'Hello');
 * // If email sending fails, automatically retries up to 3 times
 * // with exponential backoff: 1s, 2s, 4s delays
 * @example
 * // Custom retry delay:
 * class ProcessPayment extends Actions
 * {
 *     use AsRetry;
 *
 *     public function handle(Order $order, float $amount): void
 *     {
 *         PaymentGateway::charge($order, $amount);
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 5; // More retries for critical operations
 *     }
 *
 *     public function getRetryDelay(): int
 *     {
 *         return 500; // 500ms base delay (faster retries)
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessPayment::run($order, 100.00);
 * // Retries with delays: 500ms, 1000ms, 2000ms, 4000ms, 8000ms
 * @example
 * // Exception-based retry filtering:
 * class FetchApiData extends Actions
 * {
 *     use AsRetry;
 *
 *     public function handle(string $url): array
 *     {
 *         $response = Http::get($url);
 *         $response->throw(); // Throws on HTTP errors
 *
 *         return $response->json();
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 3;
 *     }
 *
 *     public function shouldRetry(\Throwable $exception): bool
 *     {
 *         // Only retry on network/timeout errors, not 4xx errors
 *         if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
 *             return true; // Retry network errors
 *         }
 *
 *         if ($exception instanceof \Illuminate\Http\Client\RequestException) {
 *             $statusCode = $exception->response?->status();
 *             // Retry 5xx errors, but not 4xx errors
 *             return $statusCode >= 500;
 *         }
 *
 *         return false;
 *     }
 * }
 *
 * // Usage:
 * $result = FetchApiData::run('https://api.example.com/data');
 * // Only retries on 5xx errors or network issues, not 4xx errors
 * @example
 * // Retry with different strategies per exception type:
 * class SyncExternalData extends Actions
 * {
 *     use AsRetry;
 *
 *     public function handle(array $data): void
 *     {
 *         ExternalAPI::sync($data);
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 5;
 *     }
 *
 *     public function getRetryDelay(): int
 *     {
 *         return 2000; // 2 seconds base delay
 *     }
 *
 *     public function shouldRetry(\Throwable $exception): bool
 *     {
 *         // Retry on rate limit (429) with longer delay
 *         if ($exception instanceof \Illuminate\Http\Client\RequestException) {
 *             if ($exception->response?->status() === 429) {
 *                 // Rate limited - use longer delay
 *                 $this->retryDelay = 10000; // 10 seconds for rate limits
 *
 *                 return true;
 *             }
 *         }
 *
 *         // Retry on 5xx errors
 *         return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
 *                ($exception instanceof \Illuminate\Http\Client\RequestException &&
 *                 $exception->response?->status() >= 500);
 *     }
 * }
 * @example
 * // Retry with property-based configuration:
 * class ProcessQueue extends Actions
 * {
 *     use AsRetry;
 *
 *     // Configure via properties
 *     public int $maxRetries = 3;
 *     public int $retryDelay = 1000;
 *
 *     public function handle(): void
 *     {
 *         Queue::process();
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessQueue::make();
 * $action->maxRetries = 5; // Override for this instance
 * $action->handle();
 * @example
 * // Retry with logging:
 * class ImportData extends Actions
 * {
 *     use AsRetry;
 *
 *     public function handle(string $filePath): array
 *     {
 *         return DataImporter::import($filePath);
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 3;
 *     }
 *
 *     public function shouldRetry(\Throwable $exception): bool
 *     {
 *         $shouldRetry = $exception instanceof \RuntimeException;
 *
 *         if ($shouldRetry) {
 *             \Log::warning('Import failed, will retry', [
 *                 'file' => $this->filePath ?? 'unknown',
 *                 'exception' => get_class($exception),
 *             ]);
 *         }
 *
 *         return $shouldRetry;
 *     }
 * }
 *
 * // Usage:
 * $result = ImportData::run('/path/to/file.csv');
 * // Logs warnings on each retry attempt
 * @example
 * // Retry with circuit breaker pattern:
 * class CallExternalService extends Actions
 * {
 *     use AsRetry;
 *
 *     public int $consecutiveFailures = 0;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         $response = Http::get($endpoint);
 *         $response->throw();
 *
 *         // Reset failure count on success
 *         $this->consecutiveFailures = 0;
 *
 *         return $response->json();
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         // Reduce retries if too many consecutive failures
 *         if ($this->consecutiveFailures > 5) {
 *             return 1; // Only 1 retry if service is down
 *         }
 *
 *         return 3;
 *     }
 *
 *     public function shouldRetry(\Throwable $exception): bool
 *     {
 *         $this->consecutiveFailures++;
 *
 *         // Don't retry if service appears down
 *         if ($this->consecutiveFailures > 5) {
 *             return false;
 *         }
 *
 *         return $exception instanceof \Illuminate\Http\Client\ConnectionException;
 *     }
 * }
 * @example
 * // Retry with different delays per attempt:
 * class UploadFile extends Actions
 * {
 *     use AsRetry;
 *
 *     public function handle(string $filePath): void
 *     {
 *         Storage::disk('s3')->put($filePath, file_get_contents($filePath));
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 5; // More retries for file uploads
 *     }
 *
 *     public function getRetryDelay(): int
 *     {
 *         return 2000; // 2 seconds base delay
 *     }
 *
 *     // Override exponential backoff calculation if needed
 *     protected function calculateRetryDelay(int $attempt): int
 *     {
 *         // Custom delay schedule: 2s, 4s, 8s, 16s, 32s
 *         return $this->getRetryDelay() * pow(2, $attempt - 1);
 *     }
 * }
 *
 * // Usage:
 * $result = UploadFile::run('/path/to/large-file.zip');
 * // Retries with exponential backoff: 2s, 4s, 8s, 16s, 32s
 * @example
 * // Retry metadata in results:
 * class ProcessOrder extends Actions
 * {
 *     use AsRetry;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Process order (might fail on external service)
 *         PaymentGateway::process($order);
 *
 *         return $order;
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 3;
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessOrder::run($order);
 *
 * // Access retry metadata:
 * if (isset($result->_retry)) {
 *     $attempts = $result->_retry['attempts'];
 *     $retried = $result->_retry['retried'];
 *
 *     if ($retried) {
 *         \Log::info("Order processed after {$attempts} attempts");
 *     }
 * }
 * // $result->_retry = ['attempts' => 2, 'max_retries' => 3, 'retried' => true]
 * @example
 * // Combining with other decorators:
 * class CriticalOperation extends Actions
 * {
 *     use AsRetry;
 *     use AsTimeout;
 *     use AsThrottle;
 *
 *     public function handle(): void
 *     {
 *         // Critical operation that needs retry, timeout, and throttling
 *         ExternalService::process();
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 5;
 *     }
 *
 *     public function getRetryDelay(): int
 *     {
 *         return 1000;
 *     }
 *
 *     public function shouldRetry(\Throwable $exception): bool
 *     {
 *         // Don't retry timeout exceptions
 *         return ! ($exception instanceof \RuntimeException &&
 *                  str_contains($exception->getMessage(), 'timeout'));
 *     }
 * }
 *
 * // Usage:
 * $result = CriticalOperation::run();
 * // Combines retry, timeout, and throttling decorators
 */
trait AsRetry
{
    // This trait is now just a marker trait.
    // The actual retry logic is handled by RetryDecorator
    // which is automatically applied via RetryDesignPattern.

    /**
     * Get maximum number of retries.
     * Override this method to customize retry count.
     */
    protected function getMaxRetries(): int
    {
        if ($this->hasMethod('getMaxRetries')) {
            return $this->callMethod('getMaxRetries');
        }

        if ($this->hasProperty('maxRetries')) {
            return $this->getProperty('maxRetries');
        }

        return 3; // Default: 3 retries
    }

    /**
     * Get base retry delay in milliseconds.
     * Override this method to customize delay.
     */
    protected function getRetryDelay(): int
    {
        if ($this->hasMethod('getRetryDelay')) {
            return $this->callMethod('getRetryDelay');
        }

        if ($this->hasProperty('retryDelay')) {
            return $this->getProperty('retryDelay');
        }

        return 1000; // Default: 1 second
    }

    /**
     * Determine if the exception should trigger a retry.
     * Override this method to customize retry conditions.
     */
    protected function shouldRetry(\Throwable $exception): bool
    {
        if ($this->hasMethod('shouldRetry')) {
            return $this->callMethod('shouldRetry', [$exception]);
        }

        // Default: retry on all exceptions except fatal errors
        return ! ($exception instanceof \Error);
    }
}
