<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsMetrics;
use App\Actions\Decorators\MetricsDecorator;

/**
 * Recognizes when actions use metrics tracking capabilities.
 *
 * @example
 * // Action class:
 * class GenerateReport extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(User $user): array
 *     {
 *         // Expensive operation
 *         return ['data' => '...'];
 *     }
 * }
 *
 * // Usage:
 * GenerateReport::run($user);
 * // Automatically tracks execution metrics
 *
 * // Get metrics:
 * $metrics = GenerateReport::getMetrics();
 * // Returns: ['calls' => 150, 'avg_duration_ms' => 500, 'success_rate' => 0.98]
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsMetrics and decorates it to track performance metrics.
 */
class MetricsDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsMetrics::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsMetrics trait
        // The decorator will handle metrics tracking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(MetricsDecorator::class, ['action' => $instance]);
    }
}
