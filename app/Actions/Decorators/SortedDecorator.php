<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sorted Decorator
 *
 * Automatically applies sorting to query results from request parameters.
 * This decorator intercepts handle() calls and applies sorting when the
 * result is an Eloquent Builder instance.
 *
 * Features:
 * - Automatic sorting from URL parameters
 * - Column whitelist validation (security)
 * - Default sorting configuration
 * - Works with any Builder instance
 * - Simple request-based API
 *
 * How it works:
 * 1. When an action uses AsSorted, SortedDesignPattern recognizes it
 * 2. ActionManager wraps the action with SortedDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action
 *    - Checks if result is a Builder instance
 *    - Reads 'sort' and 'direction' from request parameters
 *    - Validates sort column against allowed sortable columns
 *    - Applies orderBy() to the query
 *    - Returns the sorted Builder
 *
 * Request Parameters:
 * - `sort`: Column name to sort by (must be in sortable columns)
 * - `direction`: Sort direction ('asc' or 'desc', defaults to 'asc')
 */
class SortedDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action and apply sorting if result is a Builder.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $result = $this->action->handle(...$arguments);

        // Only apply sorting if result is a Builder instance
        if ($result instanceof Builder) {
            return $this->applySorting($result);
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
     * Apply sorting to the query based on request parameters.
     */
    protected function applySorting(Builder $query): Builder
    {
        $sortColumn = request()->input('sort', $this->getDefaultSort());
        $direction = request()->input('direction', $this->getDefaultSortDirection());

        if (! $sortColumn) {
            return $query;
        }

        $sortableColumns = $this->getSortableColumns();

        if (! in_array($sortColumn, $sortableColumns)) {
            return $query; // Skip non-sortable columns
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sortColumn, $direction);
    }

    /**
     * Get sortable columns from the action.
     *
     * @return array<int, string>
     */
    protected function getSortableColumns(): array
    {
        if ($this->hasMethod('getSortableColumns')) {
            return $this->callMethod('getSortableColumns');
        }

        if ($this->hasProperty('sortableColumns')) {
            return $this->getProperty('sortableColumns');
        }

        return [];
    }

    /**
     * Get default sort column from the action.
     */
    protected function getDefaultSort(): ?string
    {
        if ($this->hasMethod('getDefaultSort')) {
            return $this->callMethod('getDefaultSort');
        }

        if ($this->hasProperty('defaultSort')) {
            return $this->getProperty('defaultSort');
        }

        return null;
    }

    /**
     * Get default sort direction from the action.
     */
    protected function getDefaultSortDirection(): string
    {
        if ($this->hasMethod('getDefaultSortDirection')) {
            return $this->callMethod('getDefaultSortDirection');
        }

        if ($this->hasProperty('defaultSortDirection')) {
            return $this->getProperty('defaultSortDirection');
        }

        return 'asc';
    }
}
