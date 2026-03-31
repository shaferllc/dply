<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRateLimiter;
use App\Actions\Decorators\ActionRateLimiterDecorator;

/**
 * Recognizes when actions use rate limiting capabilities.
 *
 * @example
 * // Action class:
 * class SendEmailVerification extends Actions
 * {
 *     use AsRateLimiter;
 *
 *     public function handle(User $user): void
 *     {
 *         Mail::to($user)->send(new VerificationEmail($user));
 *     }
 *
 *     public function buildRateLimitKey(User $user): string
 *     {
 *         return "email_verification:{$user->id}";
 *     }
 *
 *     public function getMaxAttempts(): int
 *     {
 *         return 5; // 5 attempts per user
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
 * // Automatically rate limited - throws ThrottleRequestsException if exceeded
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsRateLimiter and decorates it to enforce rate limits.
 */
class ActionRateLimiterDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRateLimiter::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsRateLimiter trait
        // The decorator will handle rate limiting
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ActionRateLimiterDecorator::class, ['action' => $instance]);
    }
}
