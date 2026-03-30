<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decorates actions when used as query builders.
 *
 * @example
 * // When an action with AsQuery is used:
 * $query = GetActiveUsers::make()->query();
 * $users = $query->get();
 *
 * // This decorator provides the query() method that calls
 * // buildQuery() or query() on the action, or returns the
 * // result of handle() if it's a Builder instance.
 */
class QueryDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function query(): Builder
    {
        // Try query() method first
        if ($this->hasMethod('query')) {
            $result = $this->callMethod('query');

            if ($result instanceof Builder) {
                return $result;
            }
        }

        // Try buildQuery() method
        if ($this->hasMethod('buildQuery')) {
            $result = $this->callMethod('buildQuery');

            if ($result instanceof Builder) {
                return $result;
            }
        }

        // Try handle() method
        if ($this->hasMethod('handle')) {
            $result = $this->callMethod('handle');

            if ($result instanceof Builder) {
                return $result;
            }
        }

        // Try getModel() and build query from it
        if ($this->hasMethod('getModel')) {
            $model = $this->callMethod('getModel');

            if (is_string($model) && class_exists($model)) {
                return $model::query();
            }

            if (is_object($model) && method_exists($model, 'query')) {
                return $model->query();
            }
        }

        throw new \RuntimeException('QueryDecorator requires a query(), buildQuery(), or getModel() method on the action.');
    }
}
