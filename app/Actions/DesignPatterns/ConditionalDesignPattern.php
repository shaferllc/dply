<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsConditional;
use App\Actions\Decorators\ConditionalDecorator;

/**
 * Recognizes when actions use conditional execution capabilities.
 *
 * @example
 * // Action class:
 * class FeatureGatedAction extends Actions
 * {
 *     use AsConditional;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 *
 *     protected function shouldExecute(): bool
 *     {
 *         return config('features.new_feature', false);
 *     }
 * }
 *
 * // Usage:
 * FeatureGatedAction::run();
 * // Only executes if shouldExecute() returns true, otherwise returns null or onSkipped() result
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsConditional and decorates it to support conditional execution.
 */
class ConditionalDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsConditional::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsConditional trait
        // The decorator will handle conditional execution
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ConditionalDecorator::class, ['action' => $instance]);
    }
}
