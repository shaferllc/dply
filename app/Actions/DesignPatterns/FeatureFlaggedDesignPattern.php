<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsFeatureFlagged;
use App\Actions\Decorators\FeatureFlaggedDecorator;

/**
 * Recognizes when actions use feature flags.
 *
 * @example
 * // Action class:
 * class NewPaymentProcessor extends Actions
 * {
 *     use AsFeatureFlagged;
 *
 *     public function handle(Order $order): void
 *     {
 *         // New payment logic
 *     }
 * }
 *
 * // Usage:
 * NewPaymentProcessor::run($order);
 * // Only executes if feature flag is enabled
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsFeatureFlagged and decorates it to add feature flag checking.
 */
class FeatureFlaggedDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsFeatureFlagged::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsFeatureFlagged trait
        // The decorator will handle feature flag checking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(FeatureFlaggedDecorator::class, ['action' => $instance]);
    }
}
