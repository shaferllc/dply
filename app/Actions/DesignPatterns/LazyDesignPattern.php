<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsLazy;
use App\Actions\Decorators\LazyDecorator;

/**
 * Recognizes when actions use lazy evaluation.
 *
 * @example
 * // Action class:
 * class ExpensiveCalculation extends Actions
 * {
 *     use AsLazy;
 *
 *     public function handle(array $data): array
 *     {
 *         // Expensive operation
 *         return performExpensiveCalculation($data);
 *     }
 * }
 *
 * // Usage:
 * $lazy = ExpensiveCalculation::make($data);
 * // Calculation hasn't run yet
 *
 * $result = $lazy->get(); // Now it executes
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsLazy and decorates it to add lazy evaluation.
 */
class LazyDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsLazy::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsLazy trait
        // The decorator will handle lazy evaluation
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(LazyDecorator::class, ['action' => $instance]);
    }
}
