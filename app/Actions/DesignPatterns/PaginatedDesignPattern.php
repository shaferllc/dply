<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsPaginated;
use App\Actions\Decorators\PaginatedDecorator;

/**
 * Recognizes when actions use pagination capabilities.
 *
 * @example
 * // Action class:
 * class GetUsers extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): Builder
 *     {
 *         return User::query();
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         return 25;
 *     }
 * }
 *
 * // Usage:
 * GetUsers::run();
 * // Automatically paginates results based on request parameters
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsPaginated and decorates it to paginate results.
 */
class PaginatedDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsPaginated::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsPaginated trait
        // The decorator will handle pagination
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(PaginatedDecorator::class, ['action' => $instance]);
    }
}
