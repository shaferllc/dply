<?php

namespace App\Actions\Concerns;

/**
 * Allows actions to automatically convert array arguments to DTO objects.
 *
 * This trait is a marker that enables automatic DTO conversion functionality via DTODecorator.
 * When an action uses AsDTO, the decorator pattern recognizes it and
 * ActionManager wraps the action with DTODecorator.
 *
 * How it works:
 * 1. Action uses AsDTO trait (marker)
 * 2. DTODesignPattern recognizes the trait
 * 3. ActionManager wraps action with DTODecorator
 * 4. When action is called, DTODecorator converts array arguments to DTOs
 * 5. DTOs are created based on type hints or inferred class names
 *
 * Features:
 * - Automatic array-to-DTO conversion
 * - DTO class name inference (CreateUser -> CreateUserDTO)
 * - Custom DTO creation methods
 * - Support for multiple DTO arguments
 * - Type-safe DTO handling
 * - Flexible namespace configuration
 * - Works with decorator pattern for composition
 *
 * Benefits:
 * - Clean, type-safe action signatures
 * - Automatic validation through DTOs
 * - Better IDE support and autocomplete
 * - Consistent data structure handling
 * - Easy testing with array inputs
 * - Composable with other decorators
 * - No trait conflicts
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * DTODecorator, which automatically wraps actions and adds DTO conversion functionality.
 * This follows the same pattern as AsListener, AsLogger, AsMetrics, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getDTOClass()` method to specify custom DTO class
 * - Implement `createDTO(array $data)` method for custom DTO creation
 * - DTO classes should be in `App\DTOs\` or `App\DataTransferObjects\` namespace
 * - Or specify full class name in `getDTOClass()` method
 *
 * @example
 * // ============================================
 * // Example 1: Basic DTO Usage
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreateUserDTO $dto): User
 *     {
 *         return User::create([
 *             'name' => $dto->name,
 *             'email' => $dto->email,
 *             'phone' => $dto->phone,
 *         ]);
 *     }
 * }
 *
 * // DTO class (App\DTOs\CreateUserDTO):
 * class CreateUserDTO
 * {
 *     public function __construct(
 *         public string $name,
 *         public string $email,
 *         public ?string $phone = null
 *     ) {}
 * }
 *
 * // Usage - array automatically converted to DTO:
 * $user = CreateUser::run([
 *     'name' => 'John Doe',
 *     'email' => 'john@example.com',
 *     'phone' => '+1234567890',
 * ]);
 *
 * // Auto-registered! No need to manually configure.
 * // The system automatically discovers this and wraps with DTODecorator.
 * @example
 * // ============================================
 * // Example 2: Custom DTO Creation
 * // ============================================
 * class UpdateProduct extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(UpdateProductDTO $dto): Product
 *     {
 *         $product = Product::findOrFail($dto->id);
 *         $product->update([
 *             'name' => $dto->name,
 *             'price' => $dto->price,
 *             'description' => $dto->description,
 *         ]);
 *
 *         return $product;
 *     }
 *
 *     // Custom DTO creation with validation/transformation
 *     protected function createDTO(array $data): UpdateProductDTO
 *     {
 *         return new UpdateProductDTO(
 *             id: $data['id'],
 *             name: trim($data['name']),
 *             price: (float) $data['price'],
 *             description: $data['description'] ?? '',
 *         );
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Custom DTO Class Name
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(OrderData $dto): Order
 *     {
 *         // Process order using DTO
 *     }
 *
 *     // Specify custom DTO class name
 *     protected function getDTOClass(): string
 *     {
 *         return OrderData::class;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Multiple DTO Arguments
 * // ============================================
 * class TransferFunds extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(
 *         AccountDTO $from,
 *         AccountDTO $to,
 *         AmountDTO $amount
 *     ): Transaction {
 *         // Transfer logic
 *     }
 * }
 *
 * // Usage with multiple DTOs:
 * TransferFunds::run(
 *     ['id' => 1, 'type' => 'checking'],  // Converted to AccountDTO
 *     ['id' => 2, 'type' => 'savings'],  // Converted to AccountDTO
 *     ['amount' => 100.00, 'currency' => 'USD']  // Converted to AmountDTO
 * );
 * @example
 * // ============================================
 * // Example 5: DTO with Validation
 * // ============================================
 * class CreatePost extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreatePostDTO $dto): Post
 *     {
 *         // DTO already validated
 *         return Post::create([
 *             'title' => $dto->title,
 *             'content' => $dto->content,
 *             'author_id' => $dto->authorId,
 *         ]);
 *     }
 * }
 *
 * // DTO with validation:
 * class CreatePostDTO
 * {
 *     public function __construct(
 *         public string $title,
 *         public string $content,
 *         public int $authorId
 *     ) {
 *         // Validate in constructor or use Laravel Data
 *         if (empty($this->title)) {
 *             throw new \InvalidArgumentException('Title is required');
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: DTO with Nested Objects
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreateOrderDTO $dto): Order
 *     {
 *         $order = Order::create([
 *             'customer_id' => $dto->customer->id,
 *             'total' => $dto->total,
 *         ]);
 *
 *         foreach ($dto->items as $item) {
 *             $order->items()->create([
 *                 'product_id' => $item->productId,
 *                 'quantity' => $item->quantity,
 *                 'price' => $item->price,
 *             ]);
 *         }
 *
 *         return $order;
 *     }
 * }
 *
 * // Usage with nested data:
 * CreateOrder::run([
 *     'customer' => ['id' => 1, 'name' => 'John'],
 *     'total' => 150.00,
 *     'items' => [
 *         ['productId' => 1, 'quantity' => 2, 'price' => 50.00],
 *         ['productId' => 2, 'quantity' => 1, 'price' => 50.00],
 *     ],
 * ]);
 * @example
 * // ============================================
 * // Example 7: DTO with Default Values
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(NotificationDTO $dto): void
 *     {
 *         Mail::to($dto->recipient)
 *             ->send(new NotificationMail($dto->message));
 *     }
 * }
 *
 * // DTO with defaults:
 * class NotificationDTO
 * {
 *     public function __construct(
 *         public string $recipient,
 *         public string $message,
 *         public string $subject = 'Notification',
 *         public ?string $priority = null
 *     ) {}
 * }
 *
 * // Usage - defaults applied:
 * SendNotification::run([
 *     'recipient' => 'user@example.com',
 *     'message' => 'Hello!',
 *     // subject defaults to 'Notification'
 *     // priority defaults to null
 * ]);
 * @example
 * // ============================================
 * // Example 8: DTO from Request
 * // ============================================
 * class UpdateProfile extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(UpdateProfileDTO $dto): User
 *     {
 *         $user = auth()->user();
 *         $user->update([
 *             'name' => $dto->name,
 *             'bio' => $dto->bio,
 *             'avatar' => $dto->avatar,
 *         ]);
 *
 *         return $user;
 *     }
 * }
 *
 * // In controller:
 * public function update(Request $request)
 * {
 *     $user = UpdateProfile::run($request->all());
 *     return response()->json($user);
 * }
 * @example
 * // ============================================
 * // Example 9: DTO with Enum
 * // ============================================
 * enum OrderStatus: string
 * {
 *     case Pending = 'pending';
 *     case Processing = 'processing';
 *     case Completed = 'completed';
 * }
 *
 * class CreateOrder extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreateOrderDTO $dto): Order
 *     {
 *         return Order::create([
 *             'status' => $dto->status->value,
 *             'total' => $dto->total,
 *         ]);
 *     }
 * }
 *
 * // DTO with enum:
 * class CreateOrderDTO
 * {
 *     public function __construct(
 *         public OrderStatus $status,
 *         public float $total
 *     ) {}
 * }
 *
 * // Usage:
 * CreateOrder::run([
 *     'status' => OrderStatus::Pending,
 *     'total' => 100.00,
 * ]);
 * @example
 * // ============================================
 * // Example 10: DTO with Date/Time
 * // ============================================
 * class ScheduleEvent extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(ScheduleEventDTO $dto): Event
 *     {
 *         return Event::create([
 *             'name' => $dto->name,
 *             'starts_at' => $dto->startsAt,
 *             'ends_at' => $dto->endsAt,
 *         ]);
 *     }
 * }
 *
 * // DTO with dates:
 * class ScheduleEventDTO
 * {
 *     public function __construct(
 *         public string $name,
 *         public \DateTime $startsAt,
 *         public \DateTime $endsAt
 *     ) {}
 * }
 *
 * // Usage:
 * ScheduleEvent::run([
 *     'name' => 'Meeting',
 *     'startsAt' => '2024-01-15 10:00:00',
 *     'endsAt' => '2024-01-15 11:00:00',
 * ]);
 * @example
 * // ============================================
 * // Example 11: DTO with Collections
 * // ============================================
 * class BulkCreateUsers extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(BulkCreateUsersDTO $dto): \Illuminate\Support\Collection
 *     {
 *         return collect($dto->users)->map(function ($userData) {
 *             return User::create($userData);
 *         });
 *     }
 * }
 *
 * // DTO with collection:
 * class BulkCreateUsersDTO
 * {
 *     public function __construct(
 *         public array $users
 *     ) {}
 * }
 *
 * // Usage:
 * BulkCreateUsers::run([
 *     'users' => [
 *         ['name' => 'John', 'email' => 'john@example.com'],
 *         ['name' => 'Jane', 'email' => 'jane@example.com'],
 *     ],
 * ]);
 * @example
 * // ============================================
 * // Example 12: DTO with Laravel Data Package
 * // ============================================
 * use Spatie\LaravelData\Data;
 *
 * class CreateProductDTO extends Data
 * {
 *     public function __construct(
 *         public string $name,
 *         public float $price,
 *         public ?string $description = null
 *     ) {}
 * }
 *
 * class CreateProduct extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreateProductDTO $dto): Product
 *     {
 *         return Product::create($dto->toArray());
 *     }
 * }
 *
 * // Works with Laravel Data validation and transformation
 * CreateProduct::run([
 *     'name' => 'Widget',
 *     'price' => 29.99,
 * ]);
 * @example
 * // ============================================
 * // Example 13: DTO in API Endpoint
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(ProcessPaymentDTO $dto): Payment
 *     {
 *         // Process payment using DTO
 *         return Payment::create([
 *             'amount' => $dto->amount,
 *             'currency' => $dto->currency,
 *             'method' => $dto->method,
 *         ]);
 *     }
 * }
 *
 * // API route:
 * Route::post('/payments', function (Request $request) {
 *     $payment = ProcessPayment::run($request->json()->all());
 *     return response()->json($payment, 201);
 * });
 * @example
 * // ============================================
 * // Example 15: DTO with Conditional Logic
 * // ============================================
 * class CreateDocument extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreateDocumentDTO $dto): Document
 *     {
 *         $document = Document::create([
 *             'title' => $dto->title,
 *             'content' => $dto->content,
 *         ]);
 *
 *         if ($dto->publish) {
 *             $document->publish();
 *         }
 *
 *         return $document;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: DTO with Relationships
 * // ============================================
 * class AssignTask extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(AssignTaskDTO $dto): Task
 *     {
 *         $task = Task::findOrFail($dto->taskId);
 *         $task->assignTo($dto->userId);
 *         $task->setPriority($dto->priority);
 *
 *         return $task;
 *     }
 * }
 *
 * // DTO:
 * class AssignTaskDTO
 * {
 *     public function __construct(
 *         public int $taskId,
 *         public int $userId,
 *         public string $priority
 *     ) {}
 * }
 * @example
 * // ============================================
 * // Example 17: DTO with File Uploads
 * // ============================================
 * class UploadFile extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(UploadFileDTO $dto): File
 *     {
 *         $path = $dto->file->store('uploads');
 *
 *         return File::create([
 *             'name' => $dto->name,
 *             'path' => $path,
 *             'size' => $dto->file->getSize(),
 *         ]);
 *     }
 * }
 *
 * // DTO with file:
 * class UploadFileDTO
 * {
 *     public function __construct(
 *         public string $name,
 *         public \Illuminate\Http\UploadedFile $file
 *     ) {}
 * }
 * @example
 * // ============================================
 * // Example 18: DTO Testing
 * // ============================================
 * test('creates user from DTO', function () {
 *     $user = CreateUser::run([
 *         'name' => 'Test User',
 *         'email' => 'test@example.com',
 *     ]);
 *
 *     expect($user)->toBeInstanceOf(User::class);
 *     expect($user->name)->toBe('Test User');
 *     expect($user->email)->toBe('test@example.com');
 * });
 * @example
 * // ============================================
 * // Example 19: DTO with Custom Namespace
 * // ============================================
 * class ProcessInvoice extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(InvoiceData $dto): Invoice
 *     {
 *         // Process invoice
 *     }
 *
 *     protected function getDTOClass(): string
 *     {
 *         // Custom namespace
 *         return \App\DataTransferObjects\InvoiceData::class;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: DTO with Transformation
 * // ============================================
 * class ImportProduct extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(ImportProductDTO $dto): Product
 *     {
 *         return Product::create([
 *             'name' => $dto->name,
 *             'sku' => $dto->sku,
 *             'price' => $dto->price,
 *         ]);
 *     }
 *
 *     protected function createDTO(array $data): ImportProductDTO
 *     {
 *         // Transform data before creating DTO
 *         return new ImportProductDTO(
 *             name: ucwords(strtolower($data['name'])),
 *             sku: strtoupper($data['sku']),
 *             price: (float) $data['price'],
 *         );
 *     }
 * }
 * @example
 * // ============================================
 * // Example 21: DTO Combined with Other Decorators
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsDTO;
 *     use AsTransaction;
 *     use AsLogger;
 *
 *     public function handle(ProcessPaymentDTO $dto): Payment
 *     {
 *         // All decorators work together:
 *     // - DTODecorator converts array to DTO
 *     // - TransactionDecorator wraps in database transaction
 *     // - LoggerDecorator logs execution
 *         return Payment::create($dto->toArray());
 *     }
 * }
 * @example
 * // ============================================
 * // Example 22: DTO in Livewire Component
 * // ============================================
 * class CreatePostForm extends Component
 * {
 *     public function submit()
 *     {
 *         // Array from form automatically converted to DTO
 *         $post = CreatePost::run([
 *             'title' => $this->title,
 *             'content' => $this->content,
 *             'authorId' => auth()->id(),
 *         ]);
 *
 *         session()->flash('message', 'Post created!');
 *     }
 * }
 * @example
 * // ============================================
 * // Example 23: DTO in Queued Job
 * // ============================================
 * class SendBulkEmails extends Actions
 * {
 *     use AsDTO;
 *     use AsJob;
 *
 *     public function handle(SendBulkEmailsDTO $dto): void
 *     {
 *         foreach ($dto->recipients as $recipient) {
 *             Mail::to($recipient)->send(new Newsletter($dto->content));
 *         }
 *     }
 * }
 *
 * // Queue the action with array data
 * SendBulkEmails::dispatch([
 *     'recipients' => ['user1@example.com', 'user2@example.com'],
 *     'content' => 'Newsletter content',
 * ]);
 * @example
 * // ============================================
 * // Example 24: DTO with Nested DTOs
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreateOrderDTO $dto): Order
 *     {
 *         $order = Order::create([
 *             'customer_id' => $dto->customer->id,
 *             'total' => $dto->total,
 *         ]);
 *
 *         foreach ($dto->items as $item) {
 *             $order->items()->create([
 *                 'product_id' => $item->productId,
 *                 'quantity' => $item->quantity,
 *                 'price' => $item->price,
 *             ]);
 *         }
 *
 *         return $order;
 *     }
 * }
 *
 * // DTO with nested DTOs:
 * class CreateOrderDTO
 * {
 *     public function __construct(
 *         public CustomerDTO $customer,
 *         public float $total,
 *         public array $items  // Array of OrderItemDTO
 *     ) {}
 * }
 *
 * class CustomerDTO
 * {
 *     public function __construct(
 *         public int $id,
 *         public string $name
 *     ) {}
 * }
 *
 * class OrderItemDTO
 * {
 *     public function __construct(
 *         public int $productId,
 *         public int $quantity,
 *         public float $price
 *     ) {}
 * }
 * @example
 * // ============================================
 * // Example 25: DTO with Form Request
 * // ============================================
 * class UpdateProfileRequest extends FormRequest
 * {
 *     public function rules(): array
 *     {
 *         return [
 *             'name' => 'required|string',
 *             'bio' => 'nullable|string',
 *         ];
 *     }
 * }
 *
 * class UpdateProfile extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(UpdateProfileDTO $dto): User
 *     {
 *         return auth()->user()->update($dto->toArray());
 *     }
 * }
 *
 * // In controller:
 * public function update(UpdateProfileRequest $request)
 * {
 *     // Validated request data automatically converted to DTO
 *     $user = UpdateProfile::run($request->validated());
 *     return response()->json($user);
 * }
 * @example
 * // ============================================
 * // Example 26: DTO with API Resource
 * // ============================================
 * class CreateProduct extends Actions
 * {
 *     use AsDTO;
 *
 *     public function handle(CreateProductDTO $dto): Product
 *     {
 *         return Product::create($dto->toArray());
 *     }
 * }
 *
 * // In API controller:
 * Route::post('/products', function (Request $request) {
 *     $product = CreateProduct::run($request->json()->all());
 *     return new ProductResource($product);
 * });
 * @example
 * // ============================================
 * // Example 27: DTO with Caching
 * // ============================================
 * class GetUserProfile extends Actions
 * {
 *     use AsDTO;
 *     use AsCache;
 *
 *     public function handle(GetUserProfileDTO $dto): User
 *     {
 *         return User::with('profile')->findOrFail($dto->userId);
 *     }
 * }
 *
 * // Cached result with DTO input
 * $user = GetUserProfile::run(['userId' => 1]);
 * @example
 * // ============================================
 * // Example 28: DTO with Rate Limiting
 * // ============================================
 * class SendMessage extends Actions
 * {
 *     use AsDTO;
 *     use AsRateLimiter;
 *
 *     public function handle(SendMessageDTO $dto): Message
 *     {
 *         return Message::create([
 *             'from' => $dto->from,
 *             'to' => $dto->to,
 *             'content' => $dto->content,
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 29: DTO with Retry Logic
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsDTO;
 *     use AsRetry;
 *
 *     public function handle(ProcessPaymentDTO $dto): Payment
 *     {
 *         // Payment processing with automatic retry on failure
 *         return PaymentProcessor::process($dto);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 30: DTO with Event Dispatching
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsDTO;
 *     use AsEvent;
 *
 *     public function handle(CreateOrderDTO $dto): Order
 *     {
 *         $order = Order::create($dto->toArray());
 *
 *         // Dispatch event
 *         OrderCreated::dispatch($order);
 *
 *         return $order;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 31: DTO with Validation Decorator
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsDTO;
 *     use AsValidated;
 *
 *     public function handle(CreateUserDTO $dto): User
 *     {
 *         // DTO already validated by AsValidated decorator
 *         return User::create($dto->toArray());
 *     }
 *
 *     public function rules(): array
 *     {
 *         return [
 *             'name' => 'required|string',
 *             'email' => 'required|email|unique:users',
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 32: DTO with Authorization
 * // ============================================
 * class UpdatePost extends Actions
 * {
 *     use AsDTO;
 *     use AsAuthorized;
 *
 *     public function handle(UpdatePostDTO $dto): Post
 *     {
 *         $post = Post::findOrFail($dto->id);
 *         $post->update($dto->toArray());
 *
 *         return $post;
 *     }
 *
 *     public function authorize(UpdatePostDTO $dto): bool
 *     {
 *         $post = Post::findOrFail($dto->id);
 *         return auth()->user()->can('update', $post);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 33: DTO with Metrics Tracking
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsDTO;
 *     use AsMetrics;
 *
 *     public function handle(ProcessOrderDTO $dto): Order
 *     {
 *         // Metrics automatically tracked
 *         return Order::create($dto->toArray());
 *     }
 * }
 * @example
 * // ============================================
 * // Example 34: DTO with Locking
 * // ============================================
 * class UpdateInventory extends Actions
 * {
 *     use AsDTO;
 *     use AsLock;
 *
 *     public function handle(UpdateInventoryDTO $dto): Inventory
 *     {
 *         // Locked to prevent race conditions
 *         $inventory = Inventory::findOrFail($dto->id);
 *         $inventory->decrement('stock', $dto->quantity);
 *
 *         return $inventory;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 35: DTO with Timeout
 * // ============================================
 * class ProcessLargeDataset extends Actions
 * {
 *     use AsDTO;
 *     use AsTimeout;
 *
 *     public function handle(ProcessLargeDatasetDTO $dto): array
 *     {
 *         // Timeout after 30 seconds
 *         return $this->processData($dto->data);
 *     }
 *
 *     public function getTimeout(): int
 *     {
 *         return 30;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 36: DTO with Pipeline
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsDTO;
 *     use AsPipeline;
 *
 *     public function handle(ProcessOrderDTO $dto): Order
 *     {
 *         return $this->pipeline()
 *             ->send($dto)
 *             ->through([
 *                 ValidateOrder::class,
 *                 ReserveInventory::class,
 *                 CalculateShipping::class,
 *             ])
 *             ->then(fn ($dto) => Order::create($dto->toArray()));
 *     }
 * }
 */
trait AsDTO
{
    // This is a marker trait - the actual DTO conversion functionality is handled by DTODecorator
    // via the DTODesignPattern and ActionManager
}
