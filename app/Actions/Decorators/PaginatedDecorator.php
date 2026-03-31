<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pagination Decorator
 *
 * Automatically paginates query results before returning them.
 * This decorator intercepts handle() calls and automatically paginates
 * Builder instances returned from actions.
 *
 * Features:
 * - Automatic pagination of Eloquent Builder queries
 * - Configurable per-page count
 * - Custom page parameter name
 * - Request-based pagination parameters
 * - Works with Laravel's LengthAwarePaginator
 * - Preserves non-Builder results unchanged
 *
 * How it works:
 * 1. When an action uses AsPaginated, PaginatedDesignPattern recognizes it
 * 2. ActionManager wraps the action with PaginatedDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Checks if result is a Builder instance
 *    - Automatically paginates Builder results
 *    - Returns paginated results (LengthAwarePaginator)
 *    - Returns non-Builder results unchanged
 *
 * Pagination Parameters:
 * - `per_page`: Number of items per page (from request or action method)
 * - `page`: Current page number (from request)
 * - Page parameter name: Configurable via getPageParameterName()
 */
class PaginatedDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with automatic pagination.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $result = $this->action->handle(...$arguments);

        // Automatically paginate Builder instances
        if ($result instanceof Builder) {
            return $this->paginate($result);
        }

        // Return non-Builder results unchanged
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
     * Paginate a query builder.
     */
    protected function paginate(Builder $query): LengthAwarePaginator
    {
        $perPage = $this->getPerPage();
        $pageName = $this->getPageParameterName();
        $page = request()->input($pageName, 1);

        return $query->paginate($perPage, ['*'], $pageName, $page);
    }

    /**
     * Get the number of items per page.
     */
    protected function getPerPage(): int
    {
        return $this->fromActionMethodOrProperty('getPerPage', 'perPage', function () {
            return request()->input('per_page', 15);
        });
    }

    /**
     * Get the page parameter name.
     */
    protected function getPageParameterName(): string
    {
        return $this->fromActionMethodOrProperty('getPageParameterName', 'pageParameterName', 'page');
    }
}
