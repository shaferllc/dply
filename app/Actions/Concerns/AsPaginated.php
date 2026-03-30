<?php

namespace App\Actions\Concerns;

use App\Actions\Decorators\PaginatedDecorator;
use App\Actions\DesignPatterns\PaginatedDesignPattern;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Automatically paginates query results before returning them.
 *
 * Provides automatic pagination capabilities for actions, automatically
 * paginating Eloquent Builder instances returned from actions. Throws no
 * exceptions - simply paginates Builder results and leaves other results unchanged.
 *
 * How it works:
 * - PaginatedDesignPattern recognizes actions using AsPaginated
 * - ActionManager wraps the action with PaginatedDecorator
 * - When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Checks if result is a Builder instance
 *    - Automatically paginates Builder results
 *    - Returns paginated results (LengthAwarePaginator)
 *    - Returns non-Builder results unchanged
 *
 * Benefits:
 * - Automatic pagination of query results
 * - Configurable per-page count
 * - Custom page parameter name
 * - Request-based pagination parameters
 * - Works with Laravel's LengthAwarePaginator
 * - Preserves non-Builder results unchanged
 * - No code changes needed in action's handle() method
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * PaginatedDecorator, which automatically wraps actions and paginates results.
 * This follows the same pattern as AsOAuth, AsPermission, and other
 * decorator-based concerns.
 *
 * Pagination Parameters:
 * - `per_page`: Number of items per page (from request or action method)
 * - `page`: Current page number (from request)
 * - Page parameter name: Configurable via getPageParameterName()
 *
 * @example
 * // ============================================
 * // Example 1: Basic Pagination
 * // ============================================
 * class GetUsers extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return User::query();
 *     }
 * }
 *
 * // Usage:
 * GetUsers::run();
 * // Automatically paginates results
 * // GET /users?page=1&per_page=15
 * // Returns: LengthAwarePaginator with 15 users per page
 * @example
 * // ============================================
 * // Example 2: Custom Per Page Count
 * // ============================================
 * class GetPosts extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return Post::query()->latest();
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         return 25; // 25 posts per page
 *     }
 * }
 *
 * // Usage:
 * GetPosts::run();
 * // GET /posts?page=1
 * // Returns: LengthAwarePaginator with 25 posts per page
 * @example
 * // ============================================
 * // Example 3: Using Properties for Configuration
 * // ============================================
 * class GetProducts extends Actions
 * {
 *     use AsPaginated;
 *
 *     // Configure via properties
 *     public int $perPage = 20;
 *     public string $pageParameterName = 'p';
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return Product::query()->where('active', true);
 *     }
 * }
 *
 * // Usage:
 * $action = GetProducts::make();
 * $action->perPage = 50;
 * $action->handle();
 * // Uses 50 items per page
 * @example
 * // ============================================
 * // Example 4: Custom Page Parameter Name
 * // ============================================
 * class GetOrders extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return Order::query()->where('status', 'pending');
 *     }
 *
 *     public function getPageParameterName(): string
 *     {
 *         return 'p'; // Use 'p' instead of 'page'
 *     }
 * }
 *
 * // Usage:
 * GetOrders::run();
 * // GET /orders?p=2&per_page=10
 * // Uses 'p' as the page parameter name
 * @example
 * // ============================================
 * // Example 5: Pagination with Filtering
 * // ============================================
 * class GetFilteredUsers extends Actions
 * {
 *     use AsPaginated;
 *     use AsValidated;
 *
 *     public function handle(array $filters): \Illuminate\Database\Eloquent\Builder
 *     {
 *         $query = User::query();
 *
 *         if (isset($filters['role'])) {
 *             $query->where('role', $filters['role']);
 *         }
 *
 *         if (isset($filters['search'])) {
 *             $query->where('name', 'like', "%{$filters['search']}%");
 *         }
 *
 *         return $query;
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         return 20;
 *     }
 * }
 *
 * // Usage:
 * GetFilteredUsers::run(['role' => 'admin', 'search' => 'John']);
 * // GET /users?role=admin&search=John&page=1&per_page=20
 * // Returns paginated filtered results
 * @example
 * // ============================================
 * // Example 6: Pagination with Relationships
 * // ============================================
 * class GetPostsWithComments extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return Post::query()
 *             ->with(['author', 'comments'])
 *             ->latest();
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         return 10;
 *     }
 * }
 *
 * // Usage:
 * $paginator = GetPostsWithComments::run();
 * // Each post includes author and comments relationships
 * // Paginated with 10 posts per page
 * @example
 * // ============================================
 * // Example 7: Pagination with Sorting
 * // ============================================
 * class GetSortedProducts extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(string $sortBy = 'name', string $sortDir = 'asc'): \Illuminate\Database\Eloquent\Builder
 *     {
 *         $query = Product::query();
 *
 *         // Validate sort column
 *         $allowedSorts = ['name', 'price', 'created_at'];
 *         if (in_array($sortBy, $allowedSorts)) {
 *             $query->orderBy($sortBy, $sortDir);
 *         }
 *
 *         return $query;
 *     }
 * }
 *
 * // Usage:
 * GetSortedProducts::run('price', 'desc');
 * // GET /products?page=1&sort_by=price&sort_dir=desc
 * // Returns paginated products sorted by price descending
 * @example
 * // ============================================
 * // Example 8: Pagination in API Endpoints
 * // ============================================
 * class ApiGetUsers extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return User::query();
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         // API might have different default
 *         return request()->input('per_page', 50);
 *     }
 * }
 *
 * // In controller:
 * public function index()
 * {
 *     $paginator = ApiGetUsers::run();
 *
 *     return response()->json([
 *         'data' => $paginator->items(),
 *         'meta' => [
 *             'current_page' => $paginator->currentPage(),
 *             'per_page' => $paginator->perPage(),
 *             'total' => $paginator->total(),
 *             'last_page' => $paginator->lastPage(),
 *         ],
 *         'links' => [
 *             'first' => $paginator->url(1),
 *             'last' => $paginator->url($paginator->lastPage()),
 *             'prev' => $paginator->previousPageUrl(),
 *             'next' => $paginator->nextPageUrl(),
 *         ],
 *     ]);
 * }
 * @example
 * // ============================================
 * // Example 9: Pagination with Scopes
 * // ============================================
 * class GetActiveUsers extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return User::query()
 *             ->active() // Using a scope
 *             ->verified();
 *     }
 * }
 *
 * // Usage:
 * GetActiveUsers::run();
 * // Returns paginated active and verified users
 * @example
 * // ============================================
 * // Example 10: Pagination with Complex Queries
 * // ============================================
 * class GetUserActivity extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(User $user): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return Activity::query()
 *             ->where('user_id', $user->id)
 *             ->where('created_at', '>=', now()->subDays(30))
 *             ->with(['relatedModel'])
 *             ->orderBy('created_at', 'desc');
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         return 30; // 30 activities per page
 *     }
 * }
 *
 * // Usage:
 * GetUserActivity::run($user);
 * // Returns paginated user activities from last 30 days
 * @example
 * // ============================================
 * // Example 11: Pagination with Non-Builder Results
 * // ============================================
 * class GetData extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): array
 *     {
 *         // Returns array, not Builder
 *         return ['data' => 'some data'];
 *     }
 * }
 *
 * // Usage:
 * $result = GetData::run();
 * // Returns: ['data' => 'some data']
 * // Not paginated because result is not a Builder
 * // Non-Builder results are returned unchanged
 * @example
 * // ============================================
 * // Example 12: Pagination with Conditional Logic
 * // ============================================
 * class GetConditionalData extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(bool $paginate = true): \Illuminate\Database\Eloquent\Builder|array
 *     {
 *         $query = User::query();
 *
 *         if (! $paginate) {
 *             // Return array to skip pagination
 *             return $query->get()->toArray();
 *         }
 *
 *         // Return Builder to trigger pagination
 *         return $query;
 *     }
 * }
 *
 * // Usage:
 * GetConditionalData::run(true);  // Returns paginated results
 * GetConditionalData::run(false); // Returns array (not paginated)
 * @example
 * // ============================================
 * // Example 13: Pagination with Maximum Per Page
 * // ============================================
 * class GetLimitedUsers extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return User::query();
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         $requested = request()->input('per_page', 15);
 *
 *         // Enforce maximum per page
 *         return min($requested, 100); // Max 100 per page
 *     }
 * }
 *
 * // Usage:
 * GetLimitedUsers::run();
 * // GET /users?per_page=200
 * // Returns: 100 per page (capped at maximum)
 * @example
 * // ============================================
 * // Example 14: Pagination in Livewire Components
 * // ============================================
 * class GetLivewireData extends Actions
 * {
 *     use AsPaginated;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Builder
 *     {
 *         return Product::query()->where('active', true);
 *     }
 * }
 *
 * // Livewire Component:
 * class ProductList extends Component
 * {
 *     public function render()
 *     {
 *         $paginator = GetLivewireData::run();
 *
 *         return view('livewire.product-list', [
 *             'products' => $paginator,
 *         ]);
 *     }
 * }
 *
 * // Blade template:
 * // @foreach($products as $product)
 * //     {{ $product->name }}
 * // @endforeach
 * // {{ $products->links() }}
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - Search with Pagination
 * // ============================================
 * class SearchProducts extends Actions
 * {
 *     use AsPaginated;
 *     use AsValidated;
 *
 *     public function handle(array $filters): \Illuminate\Database\Eloquent\Builder
 *     {
 *         $query = Product::query();
 *
 *         if (isset($filters['category'])) {
 *             $query->where('category_id', $filters['category']);
 *         }
 *
 *         if (isset($filters['min_price'])) {
 *             $query->where('price', '>=', $filters['min_price']);
 *         }
 *
 *         if (isset($filters['max_price'])) {
 *             $query->where('price', '<=', $filters['max_price']);
 *         }
 *
 *         if (isset($filters['search'])) {
 *             $query->where(function ($q) use ($filters) {
 *                 $q->where('name', 'like', "%{$filters['search']}%")
 *                   ->orWhere('description', 'like', "%{$filters['search']}%");
 *             });
 *         }
 *
 *         return $query->orderBy('created_at', 'desc');
 *     }
 *
 *     public function getPerPage(): int
 *     {
 *         return 24; // 24 products per page (grid layout)
 *     }
 * }
 *
 * // Usage:
 * SearchProducts::run([
 *     'category' => 5,
 *     'min_price' => 10,
 *     'max_price' => 100,
 *     'search' => 'laptop',
 * ]);
 * // GET /search?category=5&min_price=10&max_price=100&search=laptop&page=1&per_page=24
 * // Returns paginated search results
 *
 * @see PaginatedDecorator
 * @see PaginatedDesignPattern
 * @see LengthAwarePaginator
 */
trait AsPaginated
{
    /**
     * Get the number of items per page.
     * Override this method to define custom per-page count.
     */
    protected function getPerPage(): int
    {
        if (property_exists($this, 'perPage')) {
            return (int) $this->perPage;
        }

        return request()->input('per_page', 15);
    }

    /**
     * Get the page parameter name.
     * Override this method to use a custom page parameter name.
     */
    protected function getPageParameterName(): string
    {
        if (property_exists($this, 'pageParameterName')) {
            return (string) $this->pageParameterName;
        }

        return 'page';
    }
}
