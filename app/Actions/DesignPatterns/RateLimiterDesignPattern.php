<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRateLimiter;
use App\Actions\Decorators\RateLimiterDecorator;
use Illuminate\Cache\RateLimiter;

/**
 * Recognizes when actions are used as rate limiters.
 *
 * @example
 * // Action class:
 * class CustomRateLimiter extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(string $key, int $maxAttempts, int $decaySeconds = 60): bool
 *     {
 *         // Custom rate limiting logic
 *         return RateLimiter::tooManyAttempts($key, $maxAttempts);
 *     }
 * }
 *
 * // Register in AuthServiceProvider or RouteServiceProvider:
 * RateLimiter::for('api', function ($request) {
 *     return CustomRateLimiter::class;
 * });
 *
 * // Usage:
 * Route::middleware(['throttle:api'])->group(function () {
 *     Route::get('/api/data', ...);
 * });
 *
 * // The design pattern automatically recognizes when the action
 * // is used as a rate limiter and decorates it appropriately.
 */
class RateLimiterDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRateLimiter::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->instanceOf(RateLimiter::class)
            || $frame->matches(RateLimiter::class, 'tooManyAttempts')
            || $frame->matches(RateLimiter::class, 'hit');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(RateLimiterDecorator::class, ['action' => $instance]);
    }
}
