<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsQuery;
use App\Actions\Decorators\QueryDecorator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recognizes when actions are used as query builders.
 *
 * @example
 * // Action class:
 * class GetActiveUsers extends Actions
 * {
 *     use AsQuery;
 *
 *     public function getModel(): string
 *     {
 *         return User::class;
 *     }
 *
 *     public function buildQuery(): Builder
 *     {
 *         return $this->getModel()::query()
 *             ->where('active', true)
 *             ->where('verified', true)
 *             ->orderBy('created_at', 'desc');
 *     }
 * }
 *
 * // Usage:
 * $users = GetActiveUsers::make()->query()->get();
 * $count = GetActiveUsers::make()->query()->count();
 *
 * // The design pattern automatically recognizes when the action
 * // is used as a query builder and decorates it appropriately.
 */
class QueryDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsQuery::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->instanceOf(Builder::class)
            || $frame->matches(Builder::class, 'get')
            || $frame->matches(Builder::class, 'first')
            || $frame->matches(Builder::class, 'count');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(QueryDecorator::class, ['action' => $instance]);
    }
}
