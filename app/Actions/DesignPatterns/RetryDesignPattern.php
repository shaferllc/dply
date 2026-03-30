<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsRetry;
use App\Actions\Decorators\RetryDecorator;

/**
 * Recognizes when actions use retry capabilities.
 *
 * @example
 * // Action class:
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
 *         return 3;
 *     }
 *
 *     public function shouldRetry(\Throwable $exception): bool
 *     {
 *         return $exception instanceof \Swift_TransportException;
 *     }
 * }
 *
 * // Usage:
 * SendEmail::run($user, 'Hello');
 * // Automatically retries up to 3 times with exponential backoff
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsRetry and decorates it to handle retries.
 */
class RetryDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsRetry::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsRetry trait
        // The decorator will handle retry logic
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(RetryDecorator::class, ['action' => $instance]);
    }
}
