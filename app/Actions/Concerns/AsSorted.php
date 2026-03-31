<?php

namespace App\Actions\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Automatically applies sorting to queries from request parameters.
 *
 * Intercepts the handle() method result and applies sorting when the result
 * is an Eloquent Builder instance. Reads sort parameters from request query
 * string and validates against allowed sortable columns.
 *
 * How it works:
 * - Intercepts handle() method result
 * - Checks if result is a Builder instance
 * - Reads 'sort' and 'direction' from request parameters
 * - Validates sort column against allowed sortable columns
 * - Applies orderBy() to the query
 * - Falls back to default sort if no sort parameter provided
 *
 * Benefits:
 * - Automatic sorting from URL parameters
 * - Column whitelist validation (security)
 * - Default sorting configuration
 * - Works with any Builder instance
 * - Simple request-based API
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * SortedDecorator, which automatically wraps actions and applies sorting
 * to Builder results. This follows the same pattern as AsTimeout, AsThrottle,
 * and other decorator-based concerns.
 *
 * How the decorator works:
 * - When an action uses AsSorted, SortedDesignPattern recognizes it
 * - ActionManager wraps the action with SortedDecorator
 * - The decorator intercepts handle() calls
 * - If the result is a Builder, sorting is automatically applied
 * - Sorting is opt-in per action (not applied to all actions)
 *
 * Benefits of decorator pattern:
 * - No method signature conflicts (doesn't override handle())
 * - Consistent with other decorators (AsTimeout, AsThrottle, etc.)
 * - Automatic application when trait is used
 * - Can be enabled/disabled per action
 * - Clean separation of concerns
 *
 * Request Parameters:
 * - `sort`: Column name to sort by (must be in sortable columns)
 * - `direction`: Sort direction ('asc' or 'desc', defaults to 'asc')
 *
 * @example
 * // Basic usage - automatic sorting from request:
 * class GetUsers extends Actions
 * {
 *     use AsSorted;
 *
 *     public function handle(): Builder
 *     {
 *         return User::query();
 *     }
 *
 *     // Define sortable columns (required)
 *     public function getSortableColumns(): array
 *     {
 *         return ['name', 'email', 'created_at'];
 *     }
 * }
 *
 * // Usage:
 * // GET /users?sort=name&direction=asc
 * // Returns users sorted by name ascending
 *
 * // GET /users?sort=email&direction=desc
 * // Returns users sorted by email descending
 *
 * // GET /users?sort=invalid_column
 * // Invalid column is ignored, no sorting applied
 * @example
 * // With default sorting:
 * class GetProducts extends Actions
 * {
 *     use AsSorted;
 *
 *     public function handle(): Builder
 *     {
 *         return Product::query()->where('active', true);
 *     }
 *
 *     public function getSortableColumns(): array
 *     {
 *         return ['name', 'price', 'created_at', 'popularity'];
 *     }
 *
 *     // Default sort when no sort parameter provided
 *     public function getDefaultSort(): string
 *     {
 *         return 'created_at';
 *     }
 *
 *     // Default direction
 *     public function getDefaultSortDirection(): string
 *     {
 *         return 'desc'; // Newest first
 *     }
 * }
 *
 * // Usage:
 * // GET /products
 * // Returns products sorted by created_at desc (default)
 *
 * // GET /products?sort=price&direction=asc
 * // Returns products sorted by price ascending
 * @example
 * // Using property-based configuration:
 * class GetOrders extends Actions
 * {
 *     use AsSorted;
 *
 *     // Define sortable columns as property
 *     public array $sortableColumns = ['id', 'total', 'created_at', 'status'];
 *
 *     // Default sort as property
 *     public ?string $defaultSort = 'created_at';
 *
 *     // Default direction as property
 *     public string $defaultSortDirection = 'desc';
 *
 *     public function handle(): Builder
 *     {
 *         return Order::query();
 *     }
 * }
 *
 * // Usage:
 * // GET /orders?sort=total&direction=desc
 * // Returns orders sorted by total descending
 * @example
 * // With relationships and joins:
 * class GetPostsWithAuthors extends Actions
 * {
 *     use AsSorted;
 *
 *     public function handle(): Builder
 *     {
 *         return Post::query()
 *             ->with('author')
 *             ->join('users', 'posts.user_id', '=', 'users.id');
 *     }
 *
 *     public function getSortableColumns(): array
 *     {
 *         // Can sort by joined table columns
 *         return [
 *             'posts.title',
 *             'posts.created_at',
 *             'users.name', // From joined table
 *             'users.email',
 *         ];
 *     }
 *
 *     public function getDefaultSort(): string
 *     {
 *         return 'posts.created_at';
 *     }
 *
 *     public function getDefaultSortDirection(): string
 *     {
 *         return 'desc';
 *     }
 * }
 *
 * // Usage:
 * // GET /posts?sort=users.name&direction=asc
 * // Returns posts sorted by author name
 * @example
 * // In Livewire components:
 * class ProductList extends Component
 * {
 *     public string $sort = 'name';
 *     public string $direction = 'asc';
 *
 *     public function getProductsProperty(): LengthAwarePaginator
 *     {
 *         // Pass sort parameters via request
 *         request()->merge([
 *             'sort' => $this->sort,
 *             'direction' => $this->direction,
 *         ]);
 *
 *         $query = GetProducts::run();
 *
 *         return $query->paginate(10);
 *     }
 *
 *     public function sortBy(string $column): void
 *     {
 *         if ($this->sort === $column) {
 *             // Toggle direction if same column
 *             $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
 *         } else {
 *             $this->sort = $column;
 *             $this->direction = 'asc';
 *         }
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.product-list', [
 *             'products' => $this->products,
 *         ]);
 *     }
 * }
 *
 * // In Blade template:
 * // <button wire:click="sortBy('name')">Sort by Name</button>
 * // <button wire:click="sortBy('price')">Sort by Price</button>
 * @example
 * // In API controllers:
 * class UserController extends Controller
 * {
 *     public function index(Request $request)
 *     {
 *         // Request parameters automatically read by AsSorted
 *         $users = GetUsers::run()->paginate(15);
 *
 *         return UserResource::collection($users);
 *     }
 * }
 *
 * // API Usage:
 * // GET /api/users?sort=email&direction=asc
 * // Returns JSON with sorted users
 * @example
 * // With dynamic sortable columns based on user permissions:
 * class GetDocuments extends Actions
 * {
 *     use AsSorted;
 *
 *     public function handle(): Builder
 *     {
 *         return Document::query();
 *     }
 *
 *     public function getSortableColumns(): array
 *     {
 *         $columns = ['title', 'created_at', 'updated_at'];
 *
 *         // Add sensitive columns only for admins
 *         if (auth()->user()?->isAdmin()) {
 *             $columns[] = 'confidential_level';
 *             $columns[] = 'owner_id';
 *         }
 *
 *         return $columns;
 *     }
 * }
 *
 * // Usage:
 * // Regular users can only sort by: title, created_at, updated_at
 * // Admins can also sort by: confidential_level, owner_id
 * @example
 * // With computed/calculated columns (using selectRaw):
 * class GetSalesReport extends Actions
 * {
 *     use AsSorted;
 *
 *     public function handle(): Builder
 *     {
 *         return Order::query()
 *             ->selectRaw('orders.*, SUM(order_items.quantity * order_items.price) as total_revenue')
 *             ->join('order_items', 'orders.id', '=', 'order_items.order_id')
 *             ->groupBy('orders.id');
 *     }
 *
 *     public function getSortableColumns(): array
 *     {
 *         return [
 *             'orders.created_at',
 *             'orders.status',
 *             'total_revenue', // Computed column
 *         ];
 *     }
 *
 *     public function getDefaultSort(): string
 *     {
 *         return 'total_revenue';
 *     }
 *
 *     public function getDefaultSortDirection(): string
 *     {
 *         return 'desc';
 *     }
 * }
 *
 * // Usage:
 * // GET /sales?sort=total_revenue&direction=desc
 * // Returns orders sorted by calculated revenue
 * @example
 * // Combining with other query modifiers:
 * class GetFilteredUsers extends Actions
 * {
 *     use AsSorted;
 *
 *     public function __construct(
 *         public ?string $role = null,
 *         public ?string $status = null
 *     ) {}
 *
 *     public function handle(): Builder
 *     {
 *         $query = User::query();
 *
 *         if ($this->role) {
 *             $query->where('role', $this->role);
 *         }
 *
 *         if ($this->status) {
 *             $query->where('status', $this->status);
 *         }
 *
 *         return $query;
 *     }
 *
 *     public function getSortableColumns(): array
 *     {
 *         return ['name', 'email', 'created_at', 'role', 'status'];
 *     }
 *
 *     public function getDefaultSort(): string
 *     {
 *         return 'name';
 *     }
 * }
 *
 * // Usage:
 * // $users = GetFilteredUsers::make(role: 'admin', status: 'active')->handle();
 * // Then apply sorting via request: ?sort=email&direction=asc
 */
trait AsSorted
{
    // This trait is now just a marker trait.
    // The actual sorting logic is handled by SortedDecorator
    // which is automatically applied via SortedDesignPattern.

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
