<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsLifecycle;
use App\Actions\Decorators\LifecycleDecorator;

/**
 * Recognizes when actions use lifecycle hooks.
 *
 * @example
 * // Action class:
 * class ProcessOrder extends Actions
 * {
 *     use AsLifecycle;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Main logic
 *         return $order;
 *     }
 *
 *     protected function beforeHandle(Order $order): void
 *     {
 *         // Prepare data, validate, etc.
 *     }
 *
 *     protected function onSuccess(Order $order, Order $result): void
 *     {
 *         // Success-specific logic
 *     }
 * }
 *
 * // Usage:
 * ProcessOrder::run($order);
 * // Lifecycle hooks are automatically called in order
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsLifecycle and decorates it to add lifecycle functionality.
 */
class LifecycleDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsLifecycle::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsLifecycle trait
        // The decorator will handle lifecycle hooks
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(LifecycleDecorator::class, ['action' => $instance]);
    }
}
