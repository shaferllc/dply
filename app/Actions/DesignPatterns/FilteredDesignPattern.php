<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsFiltered;
use App\Actions\Decorators\FilteredDecorator;

/**
 * Recognizes when actions use query filtering.
 *
 * @example
 * // Action class:
 * class GetUsers extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return User::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'email', 'status'];
 *     }
 * }
 *
 * // Usage: GET /users?filter[name]=john&filter[status]=active
 * // Automatically applies filters to query
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsFiltered and decorates it to add query filtering.
 */
class FilteredDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsFiltered::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsFiltered trait
        // The decorator will handle query filtering
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(FilteredDecorator::class, ['action' => $instance]);
    }
}
