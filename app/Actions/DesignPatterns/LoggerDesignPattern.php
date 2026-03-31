<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsLogger;
use App\Actions\Decorators\LoggerDecorator;

/**
 * Recognizes when actions use logging capabilities.
 *
 * @example
 * // Action class:
 * class ProcessPayment extends Actions
 * {
 *     use AsLogger;
 *
 *     public function handle(Order $order, float $amount): bool
 *     {
 *         // Payment processing logic
 *         return true;
 *     }
 *
 *     // Optional: customize log channel
 *     protected function getLogChannel(): string
 *     {
 *         return 'payments';
 *     }
 * }
 *
 * // Usage:
 * ProcessPayment::run($order, 100.00);
 * // Automatically logs: start time, parameters, result, duration, exceptions
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsLogger and decorates it to add logging functionality.
 */
class LoggerDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsLogger::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsLogger trait
        // The decorator will handle logging
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(LoggerDecorator::class, ['action' => $instance]);
    }
}
