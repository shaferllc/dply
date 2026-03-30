<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsIdempotent;
use App\Actions\Decorators\IdempotentDecorator;

/**
 * Recognizes when actions use idempotency.
 *
 * @example
 * // Action class:
 * class ProcessPayment extends Actions
 * {
 *     use AsIdempotent;
 *
 *     public function handle(Order $order, float $amount): void
 *     {
 *         // Payment processing logic
 *     }
 * }
 *
 * // Usage:
 * ProcessPayment::run($order, 100.00);
 * // Automatically prevents duplicate execution using idempotency keys
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsIdempotent and decorates it to add idempotency protection.
 */
class IdempotentDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsIdempotent::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsIdempotent trait
        // The decorator will handle idempotency protection
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(IdempotentDecorator::class, ['action' => $instance]);
    }
}
