<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsCostTracked;
use App\Actions\Decorators\CostTrackingDecorator;

/**
 * Recognizes when actions use cost tracking capabilities.
 *
 * @example
 * // Action class:
 * class CallExternalAPI extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         $this->incrementCost('api_calls', 1);
 *         $this->incrementCost('api_cost', 0.001);
 *
 *         return Http::get($endpoint)->json();
 *     }
 * }
 *
 * // Usage:
 * CallExternalAPI::run('https://api.example.com/data');
 * // Automatically tracks costs and enforces limits
 *
 * // Get costs:
 * $costs = CallExternalAPI::getCosts('daily');
 * // Returns: ['api_calls' => 1, 'api_cost' => 0.001]
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsCostTracked and decorates it to track resource costs.
 */
class CostTrackingDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsCostTracked::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsCostTracked trait
        // The decorator will handle cost tracking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(CostTrackingDecorator::class, ['action' => $instance]);
    }
}
