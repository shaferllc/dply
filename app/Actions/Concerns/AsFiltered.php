<?php

namespace App\Actions\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Automatically applies filters to queries from request parameters.
 *
 * This trait is a marker that enables automatic query filtering via FilteredDecorator.
 * When an action uses AsFiltered, FilteredDesignPattern recognizes it and
 * ActionManager wraps the action with FilteredDecorator.
 *
 * How it works:
 * 1. Action uses AsFiltered trait (marker)
 * 2. FilteredDesignPattern recognizes the trait
 * 3. ActionManager wraps action with FilteredDecorator
 * 4. When handle() is called, the decorator:
 *    - Executes the action
 *    - Checks if result is a Builder instance
 *    - Applies filters from request parameters (?filter[column]=value)
 *    - Returns the filtered query
 *
 * Benefits:
 * - Automatic query filtering from request
 * - Configurable filterable columns
 * - Custom operators per column
 * - Support for 'like', 'in', and standard operators
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * FilteredDecorator, which automatically wraps actions and adds query filtering.
 * This follows the same pattern as AsLogger, AsLock, AsIdempotent, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getFilterableColumns()` method to specify filterable columns
 * - Set `$filterableColumns` property as array of column names
 * - Implement `getFilterOperators()` method to customize operators per column
 *
 * Filter Operators:
 * - '=' (default) - Exact match
 * - 'like' - Partial match (wraps value with %)
 * - 'in' - Value in array (comma-separated or array)
 * - Any valid SQL operator: '>', '>=', '<', '<=', '!=', etc.
 *
 * Request Format:
 * - GET /endpoint?filter[column]=value
 * - GET /endpoint?filter[name]=john&filter[status]=active
 * - GET /endpoint?filter[status]=active,pending (for 'in' operator)
 *
 * @example
 * // ============================================
 * // Example 1: Basic Filtering
 * // ============================================
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
 *         return ['name', 'email', 'status', 'created_at'];
 *     }
 * }
 *
 * // Usage: GET /users?filter[name]=john&filter[status]=active
 * // Automatically applies filters to query
 * @example
 * // ============================================
 * // Example 2: Custom Filter Operators
 * // ============================================
 * class GetProducts extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Product::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'price', 'status', 'created_at'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'name' => 'like',        // Partial match
 *             'price' => '>=',         // Greater than or equal
 *             'status' => '=',         // Exact match
 *             'created_at' => '>=',    // Date range
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /products?filter[name]=widget&filter[price]=100&filter[status]=active
 * @example
 * // ============================================
 * // Example 3: Using 'in' Operator
 * // ============================================
 * class GetOrders extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Order::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['status', 'payment_method'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'status' => 'in',           // Multiple values
 *             'payment_method' => 'in',   // Multiple values
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /orders?filter[status]=pending,processing
 * // GET /orders?filter[status][]=pending&filter[status][]=processing
 * @example
 * // ============================================
 * // Example 4: Property-Based Configuration
 * // ============================================
 * class GetPosts extends Actions
 * {
 *     use AsFiltered;
 *
 *     protected array $filterableColumns = ['title', 'author_id', 'status', 'published_at'];
 *
 *     public function handle(): Builder
 *     {
 *         return Post::query();
 *     }
 * }
 *
 * // Filterable columns defined via property
 * @example
 * // ============================================
 * // Example 5: Combining with Other Decorators
 * // ============================================
 * class GetFilteredUsers extends Actions
 * {
 *     use AsFiltered;
 *     use AsLogger;
 *     use AsLifecycle;
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
 * // All decorators work together:
 * // - FilteredDecorator applies filters
 * // - LoggerDecorator tracks execution
 * // - LifecycleDecorator provides hooks
 * @example
 * // ============================================
 * // Example 6: Date Range Filtering
 * // ============================================
 * class GetTransactions extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Transaction::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['amount', 'created_at', 'type'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'amount' => '>=',
 *             'created_at' => '>=',
 *             'type' => '=',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /transactions?filter[amount]=100&filter[created_at]=2024-01-01
 * @example
 * // ============================================
 * // Example 7: Numeric Comparisons
 * // ============================================
 * class GetProductsByPrice extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Product::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['price', 'stock'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'price' => '<=',  // Less than or equal
 *             'stock' => '>',   // Greater than
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /products?filter[price]=100&filter[stock]=0
 * @example
 * // ============================================
 * // Example 8: Boolean Filtering
 * // ============================================
 * class GetActiveUsers extends Actions
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
 *         return ['is_active', 'is_verified', 'is_premium'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'is_active' => '=',
 *             'is_verified' => '=',
 *             'is_premium' => '=',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /users?filter[is_active]=1&filter[is_verified]=1
 * @example
 * // ============================================
 * // Example 9: Relationship Filtering
 * // ============================================
 * class GetPostsWithAuthor extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Post::with('author');
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['author_id', 'category_id', 'status'];
 *     }
 * }
 *
 * // Usage:
 * // GET /posts?filter[author_id]=1&filter[category_id]=5
 * @example
 * // ============================================
 * // Example 10: Multiple Status Filtering
 * // ============================================
 * class GetOrdersByStatus extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Order::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['status'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'status' => 'in',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /orders?filter[status]=pending,processing,shipped
 * @example
 * // ============================================
 * // Example 11: Search with Like Operator
 * // ============================================
 * class SearchUsers extends Actions
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
 *         return ['name', 'email'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'name' => 'like',
 *             'email' => 'like',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /users?filter[name]=john
 * // Matches: "John Doe", "johnny", "Johnson", etc.
 * @example
 * // ============================================
 * // Example 12: Not Equal Operator
 * // ============================================
 * class GetExcludedUsers extends Actions
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
 *         return ['status', 'role'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'status' => '!=',
 *             'role' => '!=',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /users?filter[status]=banned
 * // Returns all users where status is NOT 'banned'
 * @example
 * // ============================================
 * // Example 13: Combining Filters
 * // ============================================
 * class GetFilteredProducts extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Product::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'price', 'category_id', 'status', 'in_stock'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'name' => 'like',
 *             'price' => '<=',
 *             'category_id' => '=',
 *             'status' => 'in',
 *             'in_stock' => '=',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /products?filter[name]=widget&filter[price]=100&filter[category_id]=5&filter[status]=active,featured&filter[in_stock]=1
 * @example
 * // ============================================
 * // Example 14: Filtering with Pagination
 * // ============================================
 * class GetPaginatedUsers extends Actions
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
 * // In controller:
 * public function index()
 * {
 *     $query = GetPaginatedUsers::run();
 *     $users = $query->paginate(15);
 *
 *     return response()->json($users);
 * }
 *
 * // Filters are applied before pagination
 * @example
 * // ============================================
 * // Example 15: Filtering with Sorting
 * // ============================================
 * class GetSortedUsers extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return User::query()->orderBy('created_at', 'desc');
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'status'];
 *     }
 * }
 *
 * // Filters are applied, then sorting
 * @example
 * // ============================================
 * // Example 16: Filtering Scoped Queries
 * // ============================================
 * class GetActiveUsers extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return User::active(); // Using scope
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'email', 'role'];
 *     }
 * }
 *
 * // Scopes are applied first, then filters
 * @example
 * // ============================================
 * // Example 17: Filtering with Relationships
 * // ============================================
 * class GetPostsWithComments extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Post::with('comments');
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['title', 'author_id', 'status'];
 *     }
 * }
 *
 * // Filters apply to posts, relationships are eager loaded
 * @example
 * // ============================================
 * // Example 18: Custom Filter Logic
 * // ============================================
 * class GetCustomFilteredUsers extends Actions
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
 *
 *     // Override applyFilters in decorator if needed for custom logic
 *     // Or add additional filtering in handle() after base query
 * }
 * @example
 * // ============================================
 * // Example 19: Filtering with Default Values
 * // ============================================
 * class GetDefaultFilteredUsers extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         $query = User::query();
 *
 *         // Apply default filters if not in request
 *         if (! request()->has('filter.status')) {
 *             $query->where('status', 'active');
 *         }
 *
 *         return $query;
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'email', 'status'];
 *     }
 * }
 *
 * // Default filters + request filters
 * @example
 * // ============================================
 * // Example 20: Filtering API Responses
 * // ============================================
 * class GetApiUsers extends Actions
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
 *         return ['name', 'email', 'status', 'created_at'];
 *     }
 * }
 *
 * // In API controller:
 * public function index()
 * {
 *     $users = GetApiUsers::run()->get();
 *     return response()->json($users);
 * }
 *
 * // GET /api/users?filter[status]=active&filter[name]=john
 * @example
 * // ============================================
 * // Example 21: Filtering with Authorization
 * // ============================================
 * class GetAuthorizedUsers extends Actions
 * {
 *     use AsFiltered;
 *     use AsPermission;
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
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['view-users'];
 *     }
 * }
 *
 * // Authorization check happens first, then filtering
 * @example
 * // ============================================
 * // Example 22: Filtering with Logging
 * // ============================================
 * class GetLoggedUsers extends Actions
 * {
 *     use AsFiltered;
 *     use AsLogger;
 *
 *     public function handle(): Builder
 *     {
 *         return User::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'email'];
 *     }
 * }
 *
 * // Filters applied, execution logged
 * @example
 * // ============================================
 * // Example 23: Complex Filtering
 * // ============================================
 * class GetComplexFilteredData extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Model::query()
 *             ->with(['relation1', 'relation2'])
 *             ->where('type', 'premium');
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'status', 'category_id', 'price'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'name' => 'like',
 *             'status' => 'in',
 *             'category_id' => '=',
 *             'price' => '>=',
 *         ];
 *     }
 * }
 *
 * // Complex queries with relationships, scopes, and filters
 * @example
 * // ============================================
 * // Example 24: Filtering with Date Ranges
 * // ============================================
 * class GetDateRangeData extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Event::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['start_date', 'end_date', 'status'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'start_date' => '>=',
 *             'end_date' => '<=',
 *             'status' => '=',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /events?filter[start_date]=2024-01-01&filter[end_date]=2024-12-31
 * @example
 * // ============================================
 * // Example 25: Filtering Nullable Fields
 * // ============================================
 * class GetNullableFilteredData extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Model::query();
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['deleted_at', 'archived_at'];
 *     }
 *
 *     public function getFilterOperators(): array
 *     {
 *         return [
 *             'deleted_at' => '=',
 *             'archived_at' => '=',
 *         ];
 *     }
 * }
 *
 * // Usage:
 * // GET /data?filter[deleted_at]=null (filters for null values)
 * @example
 * // ============================================
 * // Example 26: Filtering with Eager Loading
 * // ============================================
 * class GetEagerLoadedData extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Post::with(['author', 'comments', 'tags']);
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['title', 'author_id', 'status'];
 *     }
 * }
 *
 * // Filters apply to posts, relationships eager loaded
 * @example
 * // ============================================
 * // Example 27: Filtering with Aggregates
 * // ============================================
 * class GetAggregatedData extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return Order::query()
 *             ->select('user_id', DB::raw('SUM(total) as total'))
 *             ->groupBy('user_id');
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['user_id', 'created_at'];
 *     }
 * }
 *
 * // Filters applied before grouping
 * @example
 * // ============================================
 * // Example 28: Filtering with Subqueries
 * // ============================================
 * class GetSubqueryData extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return User::whereHas('orders', function ($query) {
 *             $query->where('total', '>', 100);
 *         });
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['name', 'email', 'status'];
 *     }
 * }
 *
 * // Subquery filters + request filters
 * @example
 * // ============================================
 * // Example 29: Filtering with Joins
 * // ============================================
 * class GetJoinedData extends Actions
 * {
 *     use AsFiltered;
 *
 *     public function handle(): Builder
 *     {
 *         return User::query()
 *             ->join('profiles', 'users.id', '=', 'profiles.user_id')
 *             ->select('users.*', 'profiles.bio');
 *     }
 *
 *     public function getFilterableColumns(): array
 *     {
 *         return ['users.name', 'users.email', 'profiles.bio'];
 *     }
 * }
 *
 * // Filters can use table-prefixed columns
 * @example
 * // ============================================
 * // Example 30: Testing Filtered Actions
 * // ============================================
 * class TestableFilteredAction extends Actions
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
 *         return ['name', 'email'];
 *     }
 * }
 *
 * // In tests:
 * request()->merge(['filter' => ['name' => 'john', 'email' => 'john@example.com']]);
 * $query = TestableFilteredAction::run();
 * $users = $query->get();
 *
 * expect($users)->each->toHaveKey('name');
 */
trait AsFiltered
{
    // This is a marker trait - the actual filtering is handled by FilteredDecorator
    // via the FilteredDesignPattern and ActionManager
}
