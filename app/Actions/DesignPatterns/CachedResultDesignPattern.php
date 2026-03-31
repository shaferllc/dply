<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsCachedResult;
use App\Actions\Decorators\CachedResultDecorator;

/**
 * Recognizes when actions use result caching capabilities.
 *
 * @example
 * // Action class:
 * class ExpensiveCalculation extends Actions
 * {
 *     use AsCachedResult;
 *
 *     public function handle(int $input): int
 *     {
 *         // Expensive operation
 *         return $input * 2;
 *     }
 *
 *     protected function getCacheTtl(): int
 *     {
 *         return 3600; // 1 hour
 *     }
 * }
 *
 * // Usage:
 * ExpensiveCalculation::run(5); // Executes and caches result
 * ExpensiveCalculation::run(5); // Returns cached result
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsCachedResult and decorates it to cache results.
 */
class CachedResultDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsCachedResult::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsCachedResult trait
        // The decorator will handle result caching
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(CachedResultDecorator::class, ['action' => $instance]);
    }
}
