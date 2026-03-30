<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtered Decorator
 *
 * Automatically applies filters to database queries from request parameters.
 * This decorator intercepts handle() calls and applies filters to Builder results.
 *
 * Features:
 * - Automatic filter application from request parameters
 * - Configurable filterable columns
 * - Custom filter operators per column
 * - Support for 'like', 'in', and standard operators
 * - Only filters Builder instances
 *
 * How it works:
 * 1. When an action uses AsFiltered, FilteredDesignPattern recognizes it
 * 2. ActionManager wraps the action with FilteredDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action
 *    - Checks if result is a Builder instance
 *    - Applies filters from request parameters
 *    - Returns the filtered query
 *
 * Benefits:
 * - Automatic query filtering
 * - Request-based filtering
 * - Configurable filterable columns
 * - Custom operators per column
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 */
class FilteredDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action and apply filters if result is a Builder.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $result = $this->action->handle(...$arguments);

        if ($result instanceof Builder) {
            return $this->applyFilters($result);
        }

        return $result;
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Apply filters to the query.
     */
    protected function applyFilters(Builder $query): Builder
    {
        $filters = request()->input('filter', []);

        if (empty($filters)) {
            return $query;
        }

        $filterableColumns = $this->getFilterableColumns();

        foreach ($filters as $column => $value) {
            if (! in_array($column, $filterableColumns)) {
                continue; // Skip non-filterable columns
            }

            $operator = $this->getFilterOperator($column);

            if ($operator === 'like') {
                $query->where($column, 'like', "%{$value}%");
            } elseif ($operator === 'in') {
                $values = is_array($value) ? $value : explode(',', $value);
                $query->whereIn($column, $values);
            } else {
                $query->where($column, $operator, $value);
            }
        }

        return $query;
    }

    /**
     * Get filterable columns.
     */
    protected function getFilterableColumns(): array
    {
        return $this->fromActionMethodOrProperty('getFilterableColumns', 'filterableColumns', []);
    }

    /**
     * Get filter operator for a column.
     */
    protected function getFilterOperator(string $column): string
    {
        $operators = $this->getFilterOperators();

        return $operators[$column] ?? '=';
    }

    /**
     * Get filter operators mapping.
     */
    protected function getFilterOperators(): array
    {
        return $this->fromActionMethod('getFilterOperators', []);
    }
}
