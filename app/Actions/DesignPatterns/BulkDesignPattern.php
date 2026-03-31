<?php

declare(strict_types=1);

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsBulk;
use App\Actions\Decorators\BulkDecorator;

/**
 * Recognizes when actions use bulk operation capabilities.
 *
 * @example
 * // Action class:
 * class SendNotification extends Actions
 * {
 *     use AsBulk;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         // Send single notification
 *     }
 *
 *     protected function getBatchSize(): int
 *     {
 *         return 100;
 *     }
 * }
 *
 * // Usage:
 * SendNotification::bulk($users->map(fn($u) => [$u, 'Hello']));
 * // Automatically batches and optimizes
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsBulk and decorates it to support bulk operations.
 */
class BulkDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsBulk::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsBulk trait
        // The decorator will handle bulk operations
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(BulkDecorator::class, ['action' => $instance]);
    }
}
