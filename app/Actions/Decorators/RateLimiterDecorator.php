<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorates actions when used as rate limiters.
 *
 * @example
 * // When an action with AsRateLimiter is used:
 * RateLimiter::for('api', CustomRateLimiter::class);
 *
 * // This decorator makes the action invokable and calls handle()
 * // with the rate limiting parameters (key, maxAttempts, decaySeconds).
 * // Returns a boolean indicating if rate limit is exceeded.
 */
class RateLimiterDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function __invoke($request = null, ?string $key = null, ?int $maxAttempts = null, int $decaySeconds = 60): bool
    {
        // Rate limiters can be called with just a request, or with explicit parameters
        if ($request !== null && $key === null) {
            // Called as: RateLimiter::for('api', fn($request) => ...)
            // Build key from request
            $key = $this->buildKeyFromRequest($request);
            $maxAttempts = $this->getMaxAttempts();
        }

        // Ensure we have required parameters
        if ($key === null || $maxAttempts === null) {
            $key = $key ?? $this->getDefaultKey();
            $maxAttempts = $maxAttempts ?? $this->getMaxAttempts();
        }

        // Try handle() method with parameters
        if ($this->hasMethod('handle')) {
            $result = $this->callMethod('handle', [$key, $maxAttempts, $decaySeconds]);

            return is_bool($result) ? $result : (bool) $result;
        }

        // Try asRateLimiter() method
        if ($this->hasMethod('asRateLimiter')) {
            $result = $this->callMethod('asRateLimiter', [$key, $maxAttempts, $decaySeconds]);

            return is_bool($result) ? $result : (bool) $result;
        }

        return false;
    }

    protected function buildKeyFromRequest($request): string
    {
        if (is_object($request) && method_exists($request, 'user')) {
            $user = $request->user();

            return $user ? "rate_limit:{$user->id}" : 'rate_limit:'.$request->ip();
        }

        return 'rate_limit:'.($request->ip() ?? 'unknown');
    }

    protected function getDefaultKey(): string
    {
        return 'rate_limit:default';
    }

    protected function getMaxAttempts(): int
    {
        return $this->hasMethod('getMaxAttempts')
            ? $this->callMethod('getMaxAttempts')
            : 60;
    }
}
