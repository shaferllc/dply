<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsCompensatable;
use App\Actions\Decorators\CompensationDecorator;

/**
 * Recognizes when actions use compensation/rollback capabilities (Saga pattern).
 *
 * @example
 * // Action class:
 * class ReserveInventory extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Product $product, int $quantity): void
 *     {
 *         $product->decrement('stock', $quantity);
 *     }
 *
 *     public function compensate(Product $product, int $quantity): void
 *     {
 *         $product->increment('stock', $quantity);
 *     }
 * }
 *
 * // Usage in saga:
 * try {
 *     ReserveInventory::run($product, 5);
 *     // ... other actions
 * } catch (\Exception $e) {
 *     ReserveInventory::compensate($product, 5); // Rollback
 * }
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsCompensatable and decorates it to support compensation.
 */
class CompensationDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsCompensatable::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsCompensatable trait
        // The decorator will handle compensation tracking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(CompensationDecorator::class, ['action' => $instance]);
    }
}
