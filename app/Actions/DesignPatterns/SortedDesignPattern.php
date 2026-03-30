<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsSorted;
use App\Actions\Decorators\SortedDecorator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recognizes when actions use sorting capabilities.
 *
 * @example
 * // Action class:
 * class GetUsers extends Actions
 * {
 *     use AsSorted;
 *
 *     public function handle(): Builder
 *     {
 *         return User::query();
 *     }
 *
 *     public function getSortableColumns(): array
 *     {
 *         return ['name', 'email', 'created_at'];
 *     }
 * }
 *
 * // Usage:
 * // GET /users?sort=name&direction=asc
 * // Automatically applies sorting to query
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsSorted and decorates it to apply sorting.
 */
class SortedDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsSorted::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsSorted trait
        // The decorator will handle whether sorting should be applied
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(SortedDecorator::class, ['action' => $instance]);
    }
}
