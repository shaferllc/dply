<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsLock;
use App\Actions\Decorators\LockDecorator;

/**
 * Recognizes when actions use locking capabilities.
 *
 * @example
 * // Action class:
 * class UpdateBalance extends Actions
 * {
 *     use AsLock;
 *
 *     public function handle(Account $account, float $amount): void
 *     {
 *         // Critical section - only one execution at a time
 *         $account->increment('balance', $amount);
 *     }
 *
 *     // Optional: customize lock key
 *     protected function getLockKey(Account $account): string
 *     {
 *         return "account:{$account->id}:balance";
 *     }
 * }
 *
 * // Usage:
 * UpdateBalance::run($account, 100.00);
 * // Prevents concurrent updates with distributed locking
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsLock and decorates it to add locking functionality.
 */
class LockDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsLock::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsLock trait
        // The decorator will handle locking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(LockDecorator::class, ['action' => $instance]);
    }
}
