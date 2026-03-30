<?php

namespace App\Actions\Concerns;

/**
 * Provides serialization support for actions.
 *
 * Adds serialization capabilities to actions, allowing them to be converted
 * to arrays, JSON, and PHP serialized format. This is useful for queued jobs,
 * caching, API responses, and storing action state.
 *
 * How it works:
 * - Implements `toArray()` for array conversion
 * - Implements `jsonSerialize()` for JSON encoding (JsonSerializable interface)
 * - Implements `__serialize()` and `__unserialize()` for PHP serialization
 * - Allows custom serialization via method overrides
 * - Automatically handles object properties
 *
 * Benefits:
 * - Convert actions to arrays for API responses
 * - JSON encode actions for storage/transmission
 * - Serialize actions for queued jobs
 * - Cache action instances
 * - Store action state
 * - Custom serialization control
 *
 * Note: This is NOT a decorator - it provides utility methods for
 * serialization. It doesn't intercept handle() calls or wrap execution.
 * Serialization is opt-in and explicit.
 *
 * Does it need to be a decorator?
 * No. The current trait-based approach works well because:
 * - It provides utility methods (toArray, jsonSerialize, etc.)
 * - It doesn't need to intercept execution
 * - Serialization is explicit and opt-in
 * - It implements standard PHP interfaces (JsonSerializable)
 * - The trait pattern is simpler for this use case
 *
 * A decorator would only be needed if you wanted to automatically
 * serialize action results or add serialization metadata to results.
 * The current approach gives you full control over when and how to serialize.
 *
 * @example
 * // Basic usage - automatic property serialization:
 * class ProcessData extends Actions
 * {
 *     use AsSerializable;
 *
 *     public string $status = 'pending';
 *     public int $count = 0;
 *
 *     public function handle(array $data): array
 *     {
 *         $this->status = 'processing';
 *         $this->count = count($data);
 *
 *         return ['processed' => true, 'data' => $data];
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessData::make();
 * $action->status = 'completed';
 * $action->count = 10;
 *
 * // Convert to array
 * $array = $action->toArray();
 * // $array = ['status' => 'completed', 'count' => 10]
 *
 * // JSON encode
 * $json = json_encode($action);
 * // {"status":"completed","count":10}
 *
 * // PHP serialize
 * $serialized = serialize($action);
 * $unserialized = unserialize($serialized);
 * // $unserialized->status === 'completed'
 * @example
 * // Custom toArray() implementation:
 * class ProcessOrder extends Actions
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public int $orderId,
 *         public string $status = 'pending'
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         // Process order
 *     }
 *
 *     // Custom serialization - only include what you need
 *     public function toArray(): array
 *     {
 *         return [
 *             'action' => get_class($this),
 *             'order_id' => $this->orderId,
 *             'status' => $this->status,
 *             'timestamp' => now()->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessOrder::make(orderId: 123, status: 'processing');
 * $array = $action->toArray();
 * // $array = ['action' => 'ProcessOrder', 'order_id' => 123, 'status' => 'processing', 'timestamp' => '...']
 * @example
 * // Custom JSON serialization:
 * class GenerateReport extends Actions
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public string $reportType,
 *         public array $filters = []
 *     ) {}
 *
 *     public function handle(): array
 *     {
 *         // Generate report
 *         return ['report' => 'data'];
 *     }
 *
 *     // Custom JSON serialization
 *     public function jsonSerialize(): array
 *     {
 *         return [
 *             'type' => $this->reportType,
 *             'filters' => $this->filters,
 *             'generated_at' => now()->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = GenerateReport::make(reportType: 'sales', filters: ['year' => 2024]);
 * $json = json_encode($action);
 * // {"type":"sales","filters":{"year":2024},"generated_at":"2024-01-01T00:00:00Z"}
 * @example
 * // Using with queued jobs:
 * class SendEmail extends Actions implements \Illuminate\Contracts\Queue\ShouldQueue
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public string $to,
 *         public string $subject,
 *         public string $body
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         Mail::to($this->to)->send(new CustomMail($this->subject, $this->body));
 *     }
 * }
 *
 * // Usage:
 * // Action is automatically serialized when queued
 * SendEmail::make(
 *     to: 'user@example.com',
 *     subject: 'Welcome',
 *     body: 'Welcome to our platform!'
 * )->dispatch();
 *
 * // Laravel automatically serializes/unserializes the action
 * @example
 * // Using with caching:
 * class GetExpensiveData extends Actions
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public string $key,
 *         public array $options = []
 *     ) {}
 *
 *     public function handle(): array
 *     {
 *         // Expensive operation
 *         return ['data' => 'expensive result'];
 *     }
 *
 *     public function toArray(): array
 *     {
 *         // Include only what's needed for cache key
 *         return [
 *             'key' => $this->key,
 *             'options' => $this->options,
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = GetExpensiveData::make(key: 'user-data', options: ['include' => 'profile']);
 *
 * // Cache the action instance
 * $cacheKey = 'action:'.md5(json_encode($action));
 * Cache::remember($cacheKey, 3600, fn() => $action->handle());
 * @example
 * // Using with API responses:
 * class GetUserProfile extends Actions
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public User $user
 *     ) {}
 *
 *     public function handle(): array
 *     {
 *         return [
 *             'id' => $this->user->id,
 *             'name' => $this->user->name,
 *             'email' => $this->user->email,
 *         ];
 *     }
 *
 *     // Serialize action for API response
 *     public function toArray(): array
 *     {
 *         return [
 *             'action' => get_class($this),
 *             'user_id' => $this->user->id,
 *             'executed_at' => now()->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage in controller:
 * class UserController extends Controller
 * {
 *     public function profile(User $user)
 *     {
 *         $action = GetUserProfile::make($user);
 *         $result = $action->handle();
 *
 *         return response()->json([
 *             'data' => $result,
 *             'meta' => $action->toArray(), // Action metadata
 *         ]);
 *     }
 * }
 * @example
 * // Storing action state for later execution:
 * class ProcessBatch extends Actions
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public array $items = [],
 *         public int $processed = 0,
 *         public string $status = 'pending'
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         foreach ($this->items as $item) {
 *             $this->processItem($item);
 *             $this->processed++;
 *         }
 *         $this->status = 'completed';
 *     }
 *
 *     protected function processItem($item): void
 *     {
 *         // Process item
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessBatch::make(items: [1, 2, 3, 4, 5]);
 *
 * // Store action state
 * $stored = serialize($action);
 * Storage::put('batch-action.txt', $stored);
 *
 * // Later, restore and continue
 * $stored = Storage::get('batch-action.txt');
 * $action = unserialize($stored);
 * // $action->processed === 0, $action->status === 'pending'
 * $action->handle();
 * // $action->processed === 5, $action->status === 'completed'
 * @example
 * // Excluding sensitive data from serialization:
 * class ProcessPayment extends Actions
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public int $amount,
 *         public string $cardNumber, // Sensitive!
 *         public string $cvv // Sensitive!
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         // Process payment
 *     }
 *
 *     // Exclude sensitive data from serialization
 *     public function toArray(): array
 *     {
 *         return [
 *             'action' => get_class($this),
 *             'amount' => $this->amount,
 *             // Don't include cardNumber or cvv!
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessPayment::make(
 *     amount: 100,
 *     cardNumber: '4111111111111111',
 *     cvv: '123'
 * );
 *
 * $array = $action->toArray();
 * // $array = ['action' => 'ProcessPayment', 'amount' => 100]
 * // cardNumber and cvv are NOT included
 * @example
 * // Using with Livewire component state:
 * class FilterProducts extends Actions
 * {
 *     use AsSerializable;
 *
 *     public function __construct(
 *         public array $filters = [],
 *         public string $sortBy = 'name',
 *         public string $sortDirection = 'asc'
 *     ) {}
 *
 *     public function handle(): Builder
 *     {
 *         $query = Product::query();
 *
 *         foreach ($this->filters as $key => $value) {
 *             $query->where($key, $value);
 *         }
 *
 *         return $query->orderBy($this->sortBy, $this->sortDirection);
 *     }
 * }
 *
 * // Livewire Component:
 * class ProductList extends Component
 * {
 *     public array $filters = [];
 *     public string $sortBy = 'name';
 *
 *     public function mount(): void
 *     {
 *         // Restore action from session
 *         if (session()->has('filter_action')) {
 *             $action = unserialize(session('filter_action'));
 *             $this->filters = $action->filters;
 *             $this->sortBy = $action->sortBy;
 *         }
 *     }
 *
 *     public function applyFilters(): void
 *     {
 *         $action = FilterProducts::make(
 *             filters: $this->filters,
 *             sortBy: $this->sortBy
 *         );
 *
 *         // Store in session for persistence
 *         session(['filter_action' => serialize($action)]);
 *
 *         $this->dispatch('filters-applied');
 *     }
 *
 *     public function render(): View
 *     {
 *         $action = FilterProducts::make(
 *             filters: $this->filters,
 *             sortBy: $this->sortBy
 *         );
 *         $products = $action->handle()->paginate(10);
 *
 *         return view('livewire.product-list', ['products' => $products]);
 *     }
 * }
 *
 * // Action state persists across page refreshes!
 */
trait AsSerializable
{
    /**
     * Serialize the action to an array.
     * This method is aliased to serializeToArray() in AsAction to avoid
     * conflicts with AsResource::toArray(Request $request).
     */
    public function toArray(): array
    {
        // Check if class has overridden serializeToArray (the aliased name)
        if ($this->hasMethod('serializeToArray')) {
            return $this->callMethod('serializeToArray');
        }

        return get_object_vars($this);
    }

    public function jsonSerialize(): array
    {
        if ($this->hasMethod('jsonSerialize')) {
            return $this->callMethod('jsonSerialize');
        }

        // Call serializeToArray (which is the aliased toArray method)
        return $this->serializeToArray();
    }

    public function __serialize(): array
    {
        // Call serializeToArray (which is the aliased toArray method)
        return $this->serializeToArray();
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}
