<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsPasswordConfirmation;
use App\Actions\Decorators\PasswordConfirmationDecorator;

/**
 * Recognizes when actions use password confirmation capabilities.
 *
 * @example
 * // Action class:
 * class DeleteAccount extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         Auth::user()->delete();
 *     }
 *
 *     public function getPasswordConfirmationTimeout(): int
 *     {
 *         return 10800; // 3 hours
 *     }
 * }
 *
 * // Usage:
 * DeleteAccount::run();
 * // Automatically requires password confirmation before execution
 * // Redirects to password confirmation page if not confirmed
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsPasswordConfirmation and decorates it to check confirmation.
 */
class PasswordConfirmationDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsPasswordConfirmation::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsPasswordConfirmation trait
        // The decorator will handle password confirmation checking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(PasswordConfirmationDecorator::class, ['action' => $instance]);
    }
}
