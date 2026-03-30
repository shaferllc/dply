<?php

namespace App\Actions\Concerns;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

/**
 * Automatically adds rate limiting to actions.
 *
 * Provides rate limiting capabilities for actions, preventing them from being
 * called too frequently. Rate limits are enforced before action execution
 * and throw exceptions when exceeded.
 *
 * How it works:
 * - ActionRateLimiterDesignPattern recognizes actions using AsRateLimiter
 * - ActionManager wraps the action with ActionRateLimiterDecorator
 * - When handle() is called, the decorator:
 *    - Generates or retrieves rate limit key
 *    - Checks if rate limit is exceeded
 *    - Throws ThrottleRequestsException if limit exceeded
 *    - Records the attempt (hits the limiter)
 *    - Executes the action
 *    - Adds rate limit metadata to result
 *
 * Benefits:
 * - Prevent action abuse
 * - Configurable rate limits per action
 * - Per-user or per-IP rate limiting
 * - Custom rate limit keys
 * - Automatic exception throwing
 * - Rate limit metadata in results
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ActionRateLimiterDecorator, which automatically wraps actions and enforces
 * rate limits. This follows the same pattern as AsTimeout, AsThrottle, and
 * other decorator-based concerns.
 *
 * Rate Limit Metadata:
 * The result will include a `_rate_limit` property with:
 * - `key`: The rate limit key used
 * - `max_attempts`: Maximum attempts allowed
 * - `decay_seconds`: Decay time in seconds
 * - `remaining`: Remaining attempts (if available)
 *
 * @example
 * // Basic usage - automatic rate limiting:
 * class SendEmailVerification extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(User $user): void
 *     {
 *         Mail::to($user)->send(new VerificationEmail($user));
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 5; // Allow 5 attempts
 *     }
 *
 *     public function getRateLimitDecaySeconds(): int
 *     {
 *         return 300; // 5 minutes
 *     }
 * }
 *
 * // Usage:
 * SendEmailVerification::run($user);
 * // If called more than 5 times in 5 minutes, throws ThrottleRequestsException
 * @example
 * // Custom rate limit key per user:
 * class ProcessPayment extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(Order $order, float $amount): void
 *     {
 *         PaymentGateway::charge($order, $amount);
 *     }
 *
 *     public function buildRateLimitKey(Order $order, float $amount): string
 *     {
 *         // Rate limit per user
 *         return "payment:user:{$order->user_id}";
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 10; // 10 payment attempts per user
 *     }
 *
 *     public function getRateLimitDecaySeconds(): int
 *     {
 *         return 3600; // 1 hour
 *     }
 * }
 *
 * // Usage:
 * ProcessPayment::run($order, 100.00);
 * // Rate limited per user - each user has separate limit
 * @example
 * // Rate limit per IP address:
 * class ApiRequest extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(array $data): array
 *     {
 *         return ApiProcessor::process($data);
 *     }
 *
 *     public function buildRateLimitKey(array $data): string
 *     {
 *         // Rate limit per IP
 *         $ip = request()->ip();
 *
 *         return "api_request:ip:{$ip}";
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 100; // 100 requests per IP
 *     }
 *
 *     public function getRateLimitDecaySeconds(): int
 *     {
 *         return 60; // 1 minute
 *     }
 * }
 *
 * // Usage:
 * ApiRequest::run($data);
 * // Rate limited per IP address
 * @example
 * // Using properties for configuration:
 * class GenerateReport extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     // Configure via properties
 *     public int $maxAttempts = 3;
 *     public int $decaySeconds = 600; // 10 minutes
 *
 *     public function handle(string $reportType): Report
 *     {
 *         return ReportGenerator::generate($reportType);
 *     }
 *
 *     public function buildRateLimitKey(string $reportType): string
 *     {
 *         $user = auth()->user();
 *
 *         return "report:{$reportType}:user:{$user->id}";
 *     }
 * }
 *
 * // Usage:
 * $action = GenerateReport::make();
 * $action->maxAttempts = 5; // Override for this instance
 * $action->handle('sales');
 * @example
 * // Custom rate limiter instance:
 * class ExpensiveOperation extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(): void
 *     {
 *         // Expensive operation
 *     }
 *
 *     public function getRateLimiterName(): string
 *     {
 *         return 'expensive_operations'; // Use custom limiter
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 2; // Very restrictive
 *     }
 *
 *     public function getRateLimitDecaySeconds(): int
 *     {
 *         return 3600; // 1 hour
 *     }
 * }
 *
 * // Register custom limiter in AppServiceProvider:
 * RateLimiter::for('expensive_operations', function ($request) {
 *     return Limit::perHour(2);
 * });
 *
 * // Usage:
 * ExpensiveOperation::run();
 * // Uses custom rate limiter instance
 * @example
 * // Rate limit with different strategies:
 * class UploadFile extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(string $filePath): void
 *     {
 *         Storage::disk('s3')->put($filePath, file_get_contents($filePath));
 *     }
 *
 *     public function buildRateLimitKey(string $filePath): string
 *     {
 *         $user = auth()->user();
 *         $fileSize = filesize($filePath);
 *
 *         // Different limits for different file sizes
 *         $tier = $fileSize > 10000000 ? 'large' : 'small';
 *
 *         return "upload:{$tier}:user:{$user->id}";
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         $fileSize = filesize($this->getFilePath());
 *
 *         // Larger files get fewer attempts
 *         return $fileSize > 10000000 ? 2 : 10;
 *     }
 *
 *     public function getRateLimitDecaySeconds(): int
 *     {
 *         $fileSize = filesize($this->getFilePath());
 *
 *         // Larger files have longer decay
 *         return $fileSize > 10000000 ? 3600 : 300;
 *     }
 *
 *     protected function getFilePath(): string
 *     {
 *         // Get file path from arguments or property
 *         return $this->filePath ?? '';
 *     }
 * }
 * @example
 * // Rate limit metadata in results:
 * class CreatePost extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(array $data): Post
 *     {
 *         return Post::create($data);
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 10;
 *     }
 * }
 *
 * // Usage:
 * $result = CreatePost::run($data);
 *
 * // Access rate limit metadata:
 * if (isset($result->_rate_limit)) {
 *     $remaining = $result->_rate_limit['remaining'];
 *     $maxAttempts = $result->_rate_limit['max_attempts'];
 *
 *     \Log::info("Post created. {$remaining} of {$maxAttempts} attempts remaining");
 * }
 * @example
 * // Custom exception class:
 * class CustomRateLimitException extends \Exception
 * {
 *     public function __construct(string $message = 'Rate limit exceeded', int $code = 429)
 *     {
 *         parent::__construct($message, $code);
 *     }
 * }
 *
 * class RestrictedAction extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(): void
 *     {
 *         // Restricted operation
 *     }
 *
 *     public function getRateLimitExceptionClass(): string
 *     {
 *         return CustomRateLimitException::class;
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 1; // Very restrictive
 *     }
 * }
 *
 * // Usage:
 * try {
 *     RestrictedAction::run();
 * } catch (CustomRateLimitException $e) {
 *     // Handle custom exception
 * }
 * @example
 * // Combining with other decorators:
 * class CriticalOperation extends Actions
 * {
 *     use AsRateLimiter;
 *     use AsRetry;
 *     use AsTimeout;
 *
 *     public function handle(): void
 *     {
 *         // Critical operation that needs rate limiting, retry, and timeout
 *         ExternalService::process();
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 5;
 *     }
 *
 *     public function getRateLimitDecaySeconds(): int
 *     {
 *         return 60;
 *     }
 * }
 *
 * // Usage:
 * CriticalOperation::run();
 * // Combines rate limiting, retry, and timeout decorators
 * @example
 * // Rate limiting in API endpoints:
 * class ApiEndpoint extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(Request $request): array
 *     {
 *         return ['data' => 'processed'];
 *     }
 *
 *     public function buildRateLimitKey(Request $request): string
 *     {
 *         // Rate limit per API key or user
 *         $apiKey = $request->header('X-API-Key');
 *         $userId = $request->user()?->id;
 *
 *         return $apiKey
 *             ? "api:key:{$apiKey}"
 *             : "api:user:{$userId}";
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         // Different limits for different API tiers
 *         $tier = $this->getApiTier();
 *
 *         return match ($tier) {
 *             'premium' => 1000,
 *             'standard' => 100,
 *             'free' => 10,
 *             default => 10,
 *         };
 *     }
 *
 *     protected function getApiTier(): string
 *     {
 *         // Determine API tier from request
 *         return request()->header('X-API-Tier', 'free');
 *     }
 * }
 * @example
 * // Rate limiting with exponential backoff:
 * class RetryWithBackoff extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(): void
 *     {
 *         // Operation that should be rate limited
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         // Start with more attempts, reduce on each failure
 *         $failures = cache()->get("failures:{$this->getKey()}", 0);
 *
 *         return max(1, 10 - $failures);
 *     }
 *
 *     public function getRateLimitDecaySeconds(): int
 *     {
 *         // Exponential backoff: 60s, 120s, 240s, 480s...
 *         $failures = cache()->get("failures:{$this->getKey()}", 0);
 *
 *         return 60 * pow(2, $failures);
 *     }
 *
 *     protected function getKey(): string
 *     {
 *         return 'retry_backoff';
 *     }
 * }
 */
trait AsRateLimiter
{
    // This trait is now just a marker trait.
    // The actual rate limiting logic is handled by ActionRateLimiterDecorator
    // which is automatically applied via ActionRateLimiterDesignPattern.

    /**
     * Build the rate limit key for the given arguments.
     * Override this method to customize rate limit key generation.
     *
     * @param  mixed  ...$arguments
     */
    protected function buildRateLimitKey(...$arguments): string
    {
        $class = get_class($this);
        $args = serialize($arguments);

        return "rate_limit:{$class}:".md5($args);
    }

    /**
     * Get maximum attempts allowed.
     * Override this method to customize max attempts.
     */
    protected function getMaxAttempts(): int
    {
        if ($this->hasProperty('maxAttempts')) {
            return (int) $this->getProperty('maxAttempts');
        }

        return 60; // Default: 60 attempts
    }

    /**
     * Get decay time in seconds.
     * Override this method to customize decay time.
     */
    protected function getRateLimitDecaySeconds(): int
    {
        if ($this->hasProperty('decaySeconds')) {
            return (int) $this->getProperty('decaySeconds');
        }

        return 60; // Default: 60 seconds
    }

    /**
     * Get the rate limiter name.
     * Override this method to use a custom rate limiter instance.
     */
    protected function getRateLimiterName(): string
    {
        return 'default';
    }

    /**
     * Get the exception class to throw when rate limit is exceeded.
     * Override this method to use a custom exception class.
     */
    protected function getRateLimitExceptionClass(): string
    {
        return ThrottleRequestsException::class;
    }
}
