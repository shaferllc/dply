<?php

namespace App\Actions\Concerns;

/**
 * Allows actions to be filled/hydrated from arrays or objects.
 *
 * This trait provides methods to populate action properties from arrays or objects,
 * similar to Laravel's model mass assignment. It supports fillable properties to
 * control which attributes can be filled.
 *
 * Features:
 * - Fill properties from arrays
 * - Fill properties from objects (models, DTOs, etc.)
 * - Support for fillable properties (whitelist)
 * - Automatic property detection
 * - Fluent interface (chainable)
 *
 * Benefits:
 * - Cleaner action instantiation
 * - Support for mass assignment
 * - Works with Laravel models, DTOs, and plain objects
 * - Type-safe property assignment
 * - Prevents mass assignment vulnerabilities
 *
 * Configuration:
 * - Implement `getFillable()` method to specify fillable properties
 * - Set `$fillable` property as array of fillable property names
 * - If neither is provided, all existing properties are fillable
 *
 * @example
 * // ============================================
 * // Example 1: Basic Hydration
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $name;
 *     public string $email;
 *     public ?string $phone = null;
 *
 *     public function handle(): User
 *     {
 *         return User::create([
 *             'name' => $this->name,
 *             'email' => $this->email,
 *             'phone' => $this->phone,
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * $action = CreateUser::make()->fill([
 *     'name' => 'John Doe',
 *     'email' => 'john@example.com',
 *     'phone' => '123-456-7890',
 * ]);
 *
 * $user = $action->handle();
 * @example
 * // ============================================
 * // Example 2: With Fillable Properties
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $name;
 *     public string $email;
 *     public string $password; // Should not be fillable
 *     public bool $isAdmin; // Should not be fillable
 *
 *     protected function getFillable(): array
 *     {
 *         return ['name', 'email']; // Only these can be filled
 *     }
 *
 *     public function handle(User $user): User
 *     {
 *         $user->update([
 *             'name' => $this->name,
 *             'email' => $this->email,
 *         ]);
 *
 *         return $user->fresh();
 *     }
 * }
 *
 * // Usage:
 * $action = UpdateUser::make()->fill([
 *     'name' => 'Jane Doe',
 *     'email' => 'jane@example.com',
 *     'password' => 'secret', // Ignored (not fillable)
 *     'isAdmin' => true, // Ignored (not fillable)
 * ]);
 * @example
 * // ============================================
 * // Example 3: Fill from Laravel Model
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsHydratable;
 *
 *     public int $orderId;
 *     public float $total;
 *     public string $status;
 *
 *     public function handle(): void
 *     {
 *         // Process order using hydrated properties
 *     }
 * }
 *
 * // Usage:
 * $order = Order::find(1);
 * $action = ProcessOrder::make()->fillFrom($order);
 * $action->handle();
 * @example
 * // ============================================
 * // Example 4: Fill from Request
 * // ============================================
 * class CreatePost extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $title;
 *     public string $content;
 *     public ?string $slug = null;
 *
 *     public function handle(): Post
 *     {
 *         return Post::create([
 *             'title' => $this->title,
 *             'content' => $this->content,
 *             'slug' => $this->slug ?? \Str::slug($this->title),
 *         ]);
 *     }
 * }
 *
 * // In controller:
 * public function store(Request $request)
 * {
 *     $action = CreatePost::make()->fill($request->validated());
 *     $post = $action->handle();
 *
 *     return response()->json($post);
 * }
 * @example
 * // ============================================
 * // Example 5: Fill from DTO
 * // ============================================
 * class UserData
 * {
 *     public function __construct(
 *         public string $name,
 *         public string $email,
 *         public ?string $phone = null
 *     ) {}
 *
 *     public function toArray(): array
 *     {
 *         return [
 *             'name' => $this->name,
 *             'email' => $this->email,
 *             'phone' => $this->phone,
 *         ];
 *     }
 * }
 *
 * class CreateUserFromDto extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $name;
 *     public string $email;
 *     public ?string $phone = null;
 *
 *     public function handle(): User
 *     {
 *         return User::create([
 *             'name' => $this->name,
 *             'email' => $this->email,
 *             'phone' => $this->phone,
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * $dto = new UserData('John Doe', 'john@example.com', '123-456-7890');
 * $action = CreateUserFromDto::make()->fillFrom($dto);
 * $user = $action->handle();
 * @example
 * // ============================================
 * // Example 6: Chaining with Other Methods
 * // ============================================
 * class FlexibleAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $data;
 *
 *     public function handle(): string
 *     {
 *         return $this->data;
 *     }
 * }
 *
 * // Usage:
 * $result = FlexibleAction::make()
 *     ->fill(['data' => 'value'])
 *     ->handle();
 * @example
 * // ============================================
 * // Example 7: Partial Filling
 * // ============================================
 * class PartialFillAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $name;
 *     public string $email;
 *     public ?string $phone = null;
 *
 *     public function handle(): void
 *     {
 *         // Use filled properties
 *     }
 * }
 *
 * // Fill only some properties
 * $action = PartialFillAction::make()->fill([
 *     'name' => 'John Doe',
 *     // email and phone remain unset/default
 * ]);
 * @example
 * // ============================================
 * // Example 8: Fillable Property Array
 * // ============================================
 * class PropertyFillableAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     protected array $fillable = ['name', 'email'];
 *
 *     public string $name;
 *     public string $email;
 *     public string $password; // Not in fillable
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // Only name and email can be filled
 * @example
 * // ============================================
 * // Example 9: Nested Object Filling
 * // ============================================
 * class NestedAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $userName;
 *     public string $userEmail;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // Fill from nested array
 * $action = NestedAction::make()->fill([
 *     'userName' => 'John Doe',
 *     'userEmail' => 'john@example.com',
 * ]);
 * @example
 * // ============================================
 * // Example 10: Fill from Multiple Sources
 * // ============================================
 * class MultiSourceAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $name;
 *     public string $email;
 *     public string $source;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // Fill from multiple sources
 * $action = MultiSourceAction::make()
 *     ->fill(['name' => 'John', 'email' => 'john@example.com'])
 *     ->fill(['source' => 'web']); // Can chain multiple fills
 * @example
 * // ============================================
 * // Example 11: Form Request Hydration
 * // ============================================
 * class ProcessFormRequest extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $title;
 *     public string $description;
 *     public array $tags = [];
 *
 *     public function handle(): Model
 *     {
 *         return Model::create([
 *             'title' => $this->title,
 *             'description' => $this->description,
 *             'tags' => $this->tags,
 *         ]);
 *     }
 * }
 *
 * // In controller with FormRequest:
 * public function store(StoreFormRequest $request)
 * {
 *     $action = ProcessFormRequest::make()->fill($request->validated());
 *     $model = $action->handle();
 *
 *     return response()->json($model);
 * }
 * @example
 * // ============================================
 * // Example 12: API Response Hydration
 * // ============================================
 * class ProcessApiResponse extends Actions
 * {
 *     use AsHydratable;
 *
 *     public int $id;
 *     public string $name;
 *     public array $metadata = [];
 *
 *     public function handle(): void
 *     {
 *         // Process API response data
 *     }
 * }
 *
 * // Fill from API response
 * $response = Http::get('https://api.example.com/data')->json();
 * $action = ProcessApiResponse::make()->fill($response);
 * $action->handle();
 * @example
 * // ============================================
 * // Example 13: Database Record Hydration
 * // ============================================
 * class ProcessRecord extends Actions
 * {
 *     use AsHydratable;
 *
 *     public int $id;
 *     public string $name;
 *     public string $status;
 *
 *     public function handle(): void
 *     {
 *         // Process record
 *     }
 * }
 *
 * // Fill from database record
 * $record = DB::table('records')->where('id', 1)->first();
 * $action = ProcessRecord::make()->fill((array) $record);
 * $action->handle();
 * @example
 * // ============================================
 * // Example 14: CSV/Excel Data Hydration
 * // ============================================
 * class ProcessCsvRow extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $name;
 *     public string $email;
 *     public ?string $phone = null;
 *
 *     public function handle(): User
 *     {
 *         return User::firstOrCreate(
 *             ['email' => $this->email],
 *             ['name' => $this->name, 'phone' => $this->phone]
 *         );
 *     }
 * }
 *
 * // Fill from CSV row
 * foreach ($csvRows as $row) {
 *     $action = ProcessCsvRow::make()->fill($row);
 *     $action->handle();
 * }
 * @example
 * // ============================================
 * // Example 15: Configuration Hydration
 * // ============================================
 * class ConfigureAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $environment;
 *     public string $database;
 *     public array $settings = [];
 *
 *     public function handle(): void
 *     {
 *         // Use configuration
 *     }
 * }
 *
 * // Fill from config
 * $action = ConfigureAction::make()->fill(config('app'));
 * $action->handle();
 * @example
 * // ============================================
 * // Example 16: Event Data Hydration
 * // ============================================
 * class ProcessEvent extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $eventType;
 *     public array $payload;
 *     public \DateTime $timestamp;
 *
 *     public function handle(): void
 *     {
 *         // Process event
 *     }
 * }
 *
 * // Fill from event
 * $event = new SomeEvent($data);
 * $action = ProcessEvent::make()->fillFrom($event);
 * $action->handle();
 * @example
 * // ============================================
 * // Example 17: Combining with Other Concerns
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsHydratable;
 *     use AsLogger;
 *     use AsLifecycle;
 *
 *     public string $data;
 *
 *     public function handle(): Result
 *     {
 *         // Action logic using $this->data
 *     }
 *
 *     protected function beforeHandle(): void
 *     {
 *         // Lifecycle hook
 *     }
 * }
 *
 * // All features work together
 * $action = ComprehensiveAction::make()
 *     ->fill(['data' => 'value'])
 *     ->handle();
 * @example
 * // ============================================
 * // Example 18: Type-Safe Hydration
 * // ============================================
 * class TypeSafeAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public int $id;
 *     public string $name;
 *     public bool $active;
 *     public array $tags = [];
 *
 *     public function handle(): void
 *     {
 *         // Properties are type-safe
 *         // $this->id is int, $this->name is string, etc.
 *     }
 * }
 *
 * // Type safety is maintained
 * $action = TypeSafeAction::make()->fill([
 *     'id' => 1,
 *     'name' => 'Test',
 *     'active' => true,
 *     'tags' => ['tag1', 'tag2'],
 * ]);
 * @example
 * // ============================================
 * // Example 19: Validation Before Filling
 * // ============================================
 * class ValidatedAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $email;
 *     public string $name;
 *
 *     public function handle(): User
 *     {
 *         // Validate before using
 *         if (! filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
 *             throw new \InvalidArgumentException('Invalid email');
 *         }
 *
 *         return User::create([
 *             'name' => $this->name,
 *             'email' => $this->email,
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Conditional Filling
 * // ============================================
 * class ConditionalAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $type;
 *     public ?string $optionalField = null;
 *
 *     public function handle(): void
 *     {
 *         if ($this->type === 'advanced' && $this->optionalField) {
 *             // Use optional field
 *         }
 *     }
 * }
 *
 * // Fill conditionally
 * $data = ['type' => 'basic'];
 * if ($needsAdvanced) {
 *     $data['optionalField'] = 'value';
 * }
 * $action = ConditionalAction::make()->fill($data);
 * @example
 * // ============================================
 * // Example 21: Default Values
 * // ============================================
 * class DefaultValueAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $status = 'pending';
 *     public int $priority = 1;
 *     public array $tags = [];
 *
 *     public function handle(): void
 *     {
 *         // Defaults are used if not filled
 *     }
 * }
 *
 * // Defaults are preserved if not in fill data
 * $action = DefaultValueAction::make()->fill(['status' => 'active']);
 * // $action->priority is still 1, $action->tags is still []
 * @example
 * // ============================================
 * // Example 22: Fill from JSON
 * // ============================================
 * class JsonAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $name;
 *     public string $value;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // Fill from JSON string
 * $json = '{"name": "test", "value": "data"}';
 * $action = JsonAction::make()->fill(json_decode($json, true));
 * $action->handle();
 * @example
 * // ============================================
 * // Example 23: Fill from Environment Variables
 * // ============================================
 * class EnvAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $apiKey;
 *     public string $endpoint;
 *
 *     public function handle(): void
 *     {
 *         // Use environment-based config
 *     }
 * }
 *
 * // Fill from environment
 * $action = EnvAction::make()->fill([
 *     'apiKey' => env('API_KEY'),
 *     'endpoint' => env('API_ENDPOINT'),
 * ]);
 * @example
 * // ============================================
 * // Example 24: Fill from Cache
 * // ============================================
 * class CacheAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $key;
 *     public mixed $value;
 *
 *     public function handle(): void
 *     {
 *         // Process cached data
 *     }
 * }
 *
 * // Fill from cache
 * $cached = Cache::get('config');
 * $action = CacheAction::make()->fill($cached);
 * $action->handle();
 * @example
 * // ============================================
 * // Example 25: Fill from Session
 * // ============================================
 * class SessionAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public int $userId;
 *     public string $sessionId;
 *
 *     public function handle(): void
 *     {
 *         // Use session data
 *     }
 * }
 *
 * // Fill from session
 * $action = SessionAction::make()->fill([
 *     'userId' => session('user_id'),
 *     'sessionId' => session()->getId(),
 * ]);
 * @example
 * // ============================================
 * // Example 26: Fill from Queue Job Payload
 * // ============================================
 * class QueueAction extends Actions
 * {
 *     use AsHydratable;
 *     use AsJob;
 *
 *     public string $task;
 *     public array $data;
 *
 *     public function handle(): void
 *     {
 *         // Process queue task
 *     }
 * }
 *
 * // Fill from queue payload
 * $payload = ['task' => 'process', 'data' => ['key' => 'value']];
 * QueueAction::make()->fill($payload)->dispatch();
 * @example
 * // ============================================
 * // Example 27: Fill from Webhook Payload
 * // ============================================
 * class WebhookAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $event;
 *     public array $payload;
 *
 *     public function handle(): void
 *     {
 *         // Process webhook
 *     }
 * }
 *
 * // Fill from webhook
 * $webhook = request()->json()->all();
 * $action = WebhookAction::make()->fill($webhook);
 * $action->handle();
 * @example
 * // ============================================
 * // Example 28: Fill from Command Arguments
 * // ============================================
 * class CommandAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $command;
 *     public array $arguments = [];
 *
 *     public function handle(): void
 *     {
 *         // Execute command
 *     }
 * }
 *
 * // Fill from command
 * $action = CommandAction::make()->fill([
 *     'command' => $command,
 *     'arguments' => $arguments,
 * ]);
 * @example
 * // ============================================
 * // Example 29: Fill from Database Query Result
 * // ============================================
 * class QueryAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public int $id;
 *     public string $name;
 *     public string $email;
 *
 *     public function handle(): void
 *     {
 *         // Process query result
 *     }
 * }
 *
 * // Fill from query
 * $user = User::where('email', 'test@example.com')->first();
 * $action = QueryAction::make()->fillFrom($user);
 * $action->handle();
 * @example
 * // ============================================
 * // Example 30: Complex Nested Filling
 * // ============================================
 * class ComplexAction extends Actions
 * {
 *     use AsHydratable;
 *
 *     public string $userName;
 *     public string $userEmail;
 *     public string $orderNumber;
 *     public float $orderTotal;
 *     public array $orderItems = [];
 *
 *     public function handle(): void
 *     {
 *         // Process complex nested data
 *     }
 *
 *     protected function getFillable(): array
 *     {
 *         return ['userName', 'userEmail', 'orderNumber', 'orderTotal', 'orderItems'];
 *     }
 * }
 *
 * // Fill from complex nested structure
 * $action = ComplexAction::make()->fill([
 *     'userName' => 'John Doe',
 *     'userEmail' => 'john@example.com',
 *     'orderNumber' => 'ORD-123',
 *     'orderTotal' => 99.99,
 *     'orderItems' => [
 *         ['product' => 'Widget', 'quantity' => 2],
 *         ['product' => 'Gadget', 'quantity' => 1],
 *     ],
 * ]);
 */
trait AsHydratable
{
    /**
     * Fill the action with attributes from an array.
     *
     * @return $this
     */
    public function fill(array $attributes): self
    {
        $fillable = $this->getFillable();

        foreach ($attributes as $key => $value) {
            if (empty($fillable) || in_array($key, $fillable)) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }
        }

        return $this;
    }

    /**
     * Fill the action with attributes from an object.
     *
     * @return $this
     */
    public function fillFrom(object $object): self
    {
        $attributes = [];

        if (method_exists($object, 'toArray')) {
            $attributes = $object->toArray();
        } elseif (method_exists($object, 'getAttributes')) {
            $attributes = $object->getAttributes();
        } else {
            $attributes = get_object_vars($object);
        }

        return $this->fill($attributes);
    }

    /**
     * Get the fillable properties.
     */
    protected function getFillable(): array
    {
        // Check if action class has its own getFillable method (not from this trait)
        $reflection = new \ReflectionClass($this);

        if ($reflection->hasMethod('getFillable')) {
            $method = $reflection->getMethod('getFillable');
            $declaringClass = $method->getDeclaringClass();

            // If getFillable is declared in the action class (not this trait), call it
            if ($declaringClass->getName() !== AsHydratable::class) {
                // Use invoke to call the class's method, avoiding recursion
                return $method->invoke($this);
            }
        }

        // Otherwise check for fillable property
        if (property_exists($this, 'fillable')) {
            return $this->fillable;
        }

        return [];
    }
}
