<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;

/**
 * Rate Limiter Decorator
 *
 * Automatically adds rate limiting to actions to prevent abuse.
 * This decorator intercepts handle() calls and enforces rate limits
 * before allowing the action to execute.
 *
 * Features:
 * - Automatic rate limiting on action execution
 * - Configurable max attempts and decay time
 * - Custom rate limit key generation
 * - Per-user or per-IP rate limiting
 * - Custom rate limiter instances
 * - Throws ThrottleRequestsException when limit exceeded
 *
 * How it works:
 * 1. When an action uses AsRateLimiter, RateLimiterDesignPattern recognizes it
 * 2. ActionManager wraps the action with ActionRateLimiterDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates or retrieves rate limit key
 *    - Checks if rate limit is exceeded
 *    - Throws exception if limit exceeded
 *    - Records the attempt (hits the limiter)
 *    - Executes the action
 *    - Returns the result
 *
 * Rate Limit Metadata:
 * The result will include a `_rate_limit` property with:
 * - `key`: The rate limit key used
 * - `max_attempts`: Maximum attempts allowed
 * - `decay_seconds`: Decay time in seconds
 * - `remaining`: Remaining attempts (if available)
 */
class ActionRateLimiterDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with rate limiting.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws ThrottleRequestsException If rate limit is exceeded
     */
    public function handle(...$arguments)
    {
        $limiter = $this->getRateLimiter();
        $key = $this->getRateLimitKey(...$arguments);
        $maxAttempts = $this->getMaxAttempts();
        $decaySeconds = $this->getRateLimitDecaySeconds();

        // Check if rate limit is exceeded
        if ($limiter->tooManyAttempts($key, $maxAttempts)) {
            throw $this->buildRateLimitException($key, $maxAttempts, $decaySeconds);
        }

        // Record the attempt
        $limiter->hit($key, $decaySeconds);

        // Execute the action
        $result = $this->action->handle(...$arguments);

        // Add rate limit metadata to result
        if (is_object($result)) {
            $result->_rate_limit = [
                'key' => $key,
                'max_attempts' => $maxAttempts,
                'decay_seconds' => $decaySeconds,
                'remaining' => $limiter->remaining($key, $maxAttempts),
            ];
        }

        return $result;
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
     * Get the rate limiter instance.
     */
    protected function getRateLimiter(): RateLimiter
    {
        // Get the RateLimiter instance from the container
        // RateLimiterFacade::limiter() returns callbacks, not the RateLimiter instance
        return app(RateLimiter::class);
    }

    /**
     * Get the rate limit key for the given arguments.
     *
     * @param  mixed  ...$arguments
     */
    protected function getRateLimitKey(...$arguments): string
    {
        if ($this->hasMethod('buildRateLimitKey')) {
            return $this->callMethod('buildRateLimitKey', $arguments);
        }

        $class = get_class($this->action);
        $args = serialize($arguments);

        return "rate_limit:{$class}:".md5($args);
    }

    /**
     * Get maximum attempts allowed.
     */
    protected function getMaxAttempts(): int
    {
        if ($this->hasMethod('getMaxAttempts')) {
            return (int) $this->callMethod('getMaxAttempts');
        }

        if ($this->hasProperty('maxAttempts')) {
            return (int) $this->getProperty('maxAttempts');
        }

        return 60; // Default: 60 attempts
    }

    /**
     * Get decay time in seconds.
     */
    protected function getRateLimitDecaySeconds(): int
    {
        if ($this->hasMethod('getRateLimitDecaySeconds')) {
            return (int) $this->callMethod('getRateLimitDecaySeconds');
        }

        if ($this->hasProperty('decaySeconds')) {
            return (int) $this->getProperty('decaySeconds');
        }

        return 60; // Default: 60 seconds
    }

    /**
     * Build the rate limit exception.
     */
    protected function buildRateLimitException(string $key, int $maxAttempts, int $decaySeconds): ThrottleRequestsException
    {
        $exceptionClass = $this->hasMethod('getRateLimitExceptionClass')
            ? $this->callMethod('getRateLimitExceptionClass')
            : ThrottleRequestsException::class;

        return new $exceptionClass('Too many attempts.');
    }
}
