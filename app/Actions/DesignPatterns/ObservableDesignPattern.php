<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsObservable;
use App\Actions\Decorators\ObservableDecorator;

/**
 * Recognizes when actions use observable capabilities.
 *
 * @example
 * // Action class:
 * class ProcessOrder extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Processing logic
 *     }
 * }
 *
 * // Usage:
 * ProcessOrder::run($order);
 * // Automatically fires events: action.started, action.completed
 *
 * // Listen to events:
 * Event::listen('action.started', function ($data) {
 *     Log::info("Action started: {$data['action']}");
 * });
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsObservable and decorates it to fire events.
 */
class ObservableDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsObservable::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsObservable trait
        // The decorator will handle event firing
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ObservableDecorator::class, ['action' => $instance]);
    }
}
