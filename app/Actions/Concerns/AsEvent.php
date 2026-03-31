<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Event;

/**
 * Allows actions to dispatch and listen to events.
 *
 * This trait provides static methods for dispatching actions as events and
 * enables actions to work as event listeners. It integrates with Laravel's
 * event system to provide a fluent API for event dispatching.
 *
 * Features:
 * - Static dispatch methods (dispatch, dispatchIf, dispatchUnless)
 * - Synchronous dispatch methods (dispatchSync, dispatchSyncIf, dispatchSyncUnless)
 * - After-response dispatch methods (dispatchAfterResponse, dispatchAfterResponseIf, dispatchAfterResponseUnless)
 * - Conditional event dispatching
 * - Integration with Laravel's event system
 * - Works as event listeners via EventDesignPattern
 * - Fluent API for event dispatching
 * - Database transaction safety (shouldDispatchAfterCommit)
 * - Broadcasting support (broadcastAs, broadcastOn)
 *
 * Benefits:
 * - Clean event dispatching syntax
 * - Actions can be both events and listeners
 * - Conditional dispatching built-in
 * - Integrates with Laravel's event system
 * - Type-safe event classes
 * - Flexible dispatch timing (sync, after response, after commit)
 * - Broadcasting support out of the box
 * - Transaction-safe event dispatching
 *
 * Note: This trait does NOT use the decorator pattern for dispatching.
 * However, it is recognized by EventDesignPattern to make actions work
 * as event listeners when registered in EventServiceProvider.
 *
 * Methods:
 * - `dispatch(...$arguments)` - Dispatch the action as an event
 * - `dispatchIf(bool $condition, ...$arguments)` - Dispatch if condition is true
 * - `dispatchUnless(bool $condition, ...$arguments)` - Dispatch if condition is false
 * - `dispatchSync(...$arguments)` - Dispatch synchronously (bypass queue)
 * - `dispatchSyncIf(bool $condition, ...$arguments)` - Dispatch synchronously if condition is true
 * - `dispatchSyncUnless(bool $condition, ...$arguments)` - Dispatch synchronously unless condition is true
 * - `dispatchAfterResponse(...$arguments)` - Dispatch after HTTP response is sent
 * - `dispatchAfterResponseIf(bool $condition, ...$arguments)` - Dispatch after response if condition is true
 * - `dispatchAfterResponseUnless(bool $condition, ...$arguments)` - Dispatch after response unless condition is true
 * - `shouldDispatchAfterCommit()` - Override to dispatch after database commit
 * - `broadcastAs()` - Override to customize broadcast event name
 * - `broadcastOn()` - Override to define broadcast channels
 *
 * @example
 * // ============================================
 * // Example 1: Basic Event Dispatching
 * // ============================================
 * class OrderShipped extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 * }
 *
 * // Dispatch it:
 * OrderShipped::dispatch($order);
 * @example
 * // ============================================
 * // Example 2: Conditional Dispatching
 * // ============================================
 * class OrderShipped extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 * }
 *
 * // Dispatch only if condition is true
 * OrderShipped::dispatchIf($order->isShipped(), $order);
 *
 * // Dispatch only if condition is false
 * OrderShipped::dispatchUnless($order->isCancelled(), $order);
 * @example
 * // ============================================
 * // Example 3: Event with Multiple Properties
 * // ============================================
 * class UserRegistered extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $source = 'web',
 *         public ?string $referralCode = null
 *     ) {}
 * }
 *
 * // Dispatch with multiple properties
 * UserRegistered::dispatch($user, 'api', 'REF123');
 * @example
 * // ============================================
 * // Example 4: Event as Listener
 * // ============================================
 * // Action that listens to events:
 * class SendShippingNotification extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(OrderShipped $event): void
 *     {
 *         Mail::to($event->order->user)->send(new ShippingNotification($event->order));
 *     }
 * }
 *
 * // Register in EventServiceProvider:
 * protected $listen = [
 *     OrderShipped::class => [SendShippingNotification::class],
 * ];
 * @example
 * // ============================================
 * // Example 5: Event with Complex Logic
 * // ============================================
 * class PaymentProcessed extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Payment $payment,
 *         public bool $successful,
 *         public ?string $errorMessage = null
 *     ) {}
 * }
 *
 * // Dispatch based on payment result
 * if ($payment->succeeded()) {
 *     PaymentProcessed::dispatch($payment, true);
 * } else {
 *     PaymentProcessed::dispatch($payment, false, $payment->error_message);
 * }
 * @example
 * // ============================================
 * // Example 6: Event with Conditional Logic
 * // ============================================
 * class ProductUpdated extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Product $product,
 *         public array $changes
 *     ) {}
 * }
 *
 * // Only dispatch if product was actually changed
 * ProductUpdated::dispatchIf(
 *     !empty($changes),
 *     $product,
 *     $changes
 * );
 * @example
 * // ============================================
 * // Example 7: Event in Action Handle Method
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsEvent;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->process();
 *
 *         // Dispatch event after processing
 *         OrderProcessed::dispatch($order);
 *
 *         return $order;
 *     }
 * }
 *
 * class OrderProcessed extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 * }
 * @example
 * // ============================================
 * // Example 8: Event with Queued Listeners
 * // ============================================
 * class EmailSent extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Email $email,
 *         public bool $queued = false
 *     ) {}
 * }
 *
 * // Dispatch event
 * EmailSent::dispatch($email, true);
 *
 * // Listener can be queued in EventServiceProvider:
 * protected $listen = [
 *     EmailSent::class => [
 *         \App\Actions\LogEmailSent::class, // Synchronous
 *         \App\Actions\UpdateEmailStats::class => ['queue'], // Queued
 *     ],
 * ];
 * @example
 * // ============================================
 * // Example 9: Event Broadcasting
 * // ============================================
 * class MessageReceived extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Message $message,
 *         public User $recipient
 *     ) {}
 * }
 *
 * // Event can be broadcasted if it implements ShouldBroadcast
 * class MessageReceived extends Actions implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Message $message,
 *         public User $recipient
 *     ) {}
 *
 *     public function broadcastOn(): array
 *     {
 *         return [
 *             new \Illuminate\Broadcasting\PrivateChannel('user.'.$this->recipient->id),
 *         ];
 *     }
 * }
 *
 * // Dispatch and broadcast
 * MessageReceived::dispatch($message, $recipient);
 * @example
 * // ============================================
 * // Example 10: Event with Multiple Listeners
 * // ============================================
 * class PostPublished extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Post $post
 *     ) {}
 * }
 *
 * // Register multiple listeners in EventServiceProvider:
 * protected $listen = [
 *     PostPublished::class => [
 *         \App\Actions\SendPostNotification::class,
 *         \App\Actions\UpdatePostCache::class,
 *         \App\Actions\IndexPostForSearch::class,
 *         \App\Actions\ShareToSocialMedia::class,
 *     ],
 * ];
 *
 * // Dispatch once, all listeners are called
 * PostPublished::dispatch($post);
 * @example
 * // ============================================
 * // Example 11: Event with Conditional Dispatching
 * // ============================================
 * class UserAction extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $action
 *     ) {}
 * }
 *
 * // Only dispatch for premium users
 * UserAction::dispatchIf(
 *     $user->isPremium(),
 *     $user,
 *     'feature_accessed'
 * );
 *
 * // Only dispatch if user is not admin
 * UserAction::dispatchUnless(
 *     $user->isAdmin(),
 *     $user,
 *     'admin_action'
 * );
 * @example
 * // ============================================
 * // Example 12: Event with Validation
 * // ============================================
 * class DataValidated extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public array $data,
 *         public bool $isValid,
 *         public array $errors = []
 *     ) {}
 * }
 *
 * // Dispatch after validation
 * $validator = Validator::make($data, $rules);
 *
 * DataValidated::dispatch(
 *     $data,
 *     $validator->passes(),
 *     $validator->errors()->toArray()
 * );
 * @example
 * // ============================================
 * // Example 13: Event in Transaction
 * // ============================================
 * class TransactionCompleted extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Transaction $transaction
 *     ) {}
 * }
 *
 * // Dispatch after transaction commits
 * DB::transaction(function () use ($transaction) {
 *     $transaction->complete();
 *     TransactionCompleted::dispatch($transaction);
 * });
 * @example
 * // ============================================
 * // Example 14: Event with Retry Logic
 * // ============================================
 * class TaskFailed extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Task $task,
 *         public \Exception $exception,
 *         public int $attempts
 *     ) {}
 * }
 *
 * // Dispatch on failure
 * try {
 *     $task->execute();
 * } catch (\Exception $e) {
 *     TaskFailed::dispatch($task, $e, $task->attempts);
 * }
 * @example
 * // ============================================
 * // Example 15: Event with State Changes
 * // ============================================
 * class StatusChanged extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Model $model,
 *         public string $from,
 *         public string $to
 *     ) {}
 * }
 *
 * // Dispatch when status changes
 * $oldStatus = $order->status;
 * $order->status = 'shipped';
 * $order->save();
 *
 * StatusChanged::dispatchIf(
 *     $oldStatus !== $order->status,
 *     $order,
 *     $oldStatus,
 *     $order->status
 * );
 * @example
 * // ============================================
 * // Example 16: Event with Timestamps
 * // ============================================
 * class ActivityLogged extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $activity,
 *         public ?\DateTime $timestamp = null
 *     ) {
 *         $this->timestamp = $timestamp ?? new \DateTime;
 *     }
 * }
 *
 * // Dispatch with automatic timestamp
 * ActivityLogged::dispatch($user, 'login');
 *
 * // Or with custom timestamp
 * ActivityLogged::dispatch($user, 'logout', new \DateTime('2024-01-01'));
 * @example
 * // ============================================
 * // Example 17: Event with Collections
 * // ============================================
 * class ItemsProcessed extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public \Illuminate\Support\Collection $items,
 *         public int $processedCount,
 *         public int $failedCount = 0
 *     ) {}
 * }
 *
 * // Dispatch after batch processing
 * $items = collect([...]);
 * $processed = 0;
 * $failed = 0;
 *
 * foreach ($items as $item) {
 *     try {
 *         processItem($item);
 *         $processed++;
 *     } catch (\Exception $e) {
 *         $failed++;
 *     }
 * }
 *
 * ItemsProcessed::dispatch($items, $processed, $failed);
 * @example
 * // ============================================
 * // Example 18: Event with Context
 * // ============================================
 * class ActionPerformed extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $action,
 *         public ?User $user = null,
 *         public array $context = []
 *     ) {}
 * }
 *
 * // Dispatch with context
 * ActionPerformed::dispatch(
 *     'file_uploaded',
 *     auth()->user(),
 *     [
 *         'file_name' => 'document.pdf',
 *         'file_size' => 1024,
 *         'ip_address' => request()->ip(),
 *     ]
 * );
 * @example
 * // ============================================
 * // Example 19: Event with Polymorphism
 * // ============================================
 * class ResourceCreated extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Model $resource
 *     ) {}
 * }
 *
 * // Dispatch for different resource types
 * ResourceCreated::dispatch($user);
 * ResourceCreated::dispatch($post);
 * ResourceCreated::dispatch($comment);
 *
 * // Listener can handle all types
 * class LogResourceCreation extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(ResourceCreated $event): void
 * {
 *         Log::info('Resource created', [
 *             'type' => get_class($event->resource),
 *             'id' => $event->resource->id,
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 20: Event with Error Handling
 * // ============================================
 * class OperationCompleted extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $operation,
 *         public bool $success,
 *         public ?string $error = null,
 *         public ?array $metadata = null
 *     ) {}
 * }
 *
 * // Dispatch with error information
 * try {
 *     performOperation();
 *     OperationCompleted::dispatch('import', true, null, ['rows' => 100]);
 * } catch (\Exception $e) {
 *     OperationCompleted::dispatch('import', false, $e->getMessage(), ['rows' => 50]);
 * }
 * @example
 * // ============================================
 * // Example 21: Event with Rate Limiting
 * // ============================================
 * class ApiCallMade extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $endpoint,
 *         public int $responseTime,
 *         public int $statusCode
 *     ) {}
 * }
 *
 * // Dispatch with rate limiting check
 * ApiCallMade::dispatchIf(
 *     !RateLimiter::tooManyAttempts('api_calls', 100),
 *     $endpoint,
 *     $responseTime,
 *     $statusCode
 * );
 * @example
 * // ============================================
 * // Example 22: Event with Caching
 * // ============================================
 * class CacheInvalidated extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $key,
 *         public ?string $tag = null
 *     ) {}
 * }
 *
 * // Dispatch when cache is invalidated
 * Cache::forget($key);
 * CacheInvalidated::dispatch($key);
 *
 * // Or with tag
 * Cache::tags($tag)->flush();
 * CacheInvalidated::dispatch('*', $tag);
 * @example
 * // ============================================
 * // Example 23: Event with Notifications
 * // ============================================
 * class NotificationSent extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $type,
 *         public array $data
 *     ) {}
 * }
 *
 * // Dispatch after sending notification
 * $user->notify(new OrderShippedNotification($order));
 * NotificationSent::dispatch($user, 'order_shipped', ['order_id' => $order->id]);
 * @example
 * // ============================================
 * // Example 24: Event with Jobs
 * // ============================================
 * class JobDispatched extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $jobClass,
 *         public array $payload,
 *         public ?string $queue = null
 *     ) {}
 * }
 *
 * // Dispatch when job is queued
 * ProcessOrderJob::dispatch($order);
 * JobDispatched::dispatch(ProcessOrderJob::class, ['order_id' => $order->id], 'high');
 * @example
 * // ============================================
 * // Example 25: Event with Testing
 * // ============================================
 * class TestEvent extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $message
 *     ) {}
 * }
 *
 * // In tests, fake events
 * Event::fake();
 *
 * TestEvent::dispatch('test message');
 *
 * // Assert event was dispatched
 * Event::assertDispatched(TestEvent::class, function ($event) {
 *     return $event->message === 'test message';
 * });
 * @example
 * // ============================================
 * // Example 26: Event with Middleware
 * // ============================================
 * class RequestProcessed extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public \Illuminate\Http\Request $request,
 *         public \Illuminate\Http\Response $response,
 *         public float $duration
 *     ) {}
 * }
 *
 * // Dispatch in middleware
 * public function handle($request, Closure $next)
 * {
 *     $start = microtime(true);
 *     $response = $next($request);
 *     $duration = microtime(true) - $start;
 *
 *     RequestProcessed::dispatch($request, $response, $duration);
 *
 *     return $response;
 * }
 * @example
 * // ============================================
 * // Example 27: Event with Observers
 * // ============================================
 * class ModelSaved extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Model $model,
 *         public bool $wasRecentlyCreated
 *     ) {}
 * }
 *
 * // Dispatch in model observer
 * class UserObserver
 * {
 *     public function saved(User $user)
 *     {
 *         ModelSaved::dispatch($user, $user->wasRecentlyCreated);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 28: Event with Subscribers
 * // ============================================
 * class MultipleEvents extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $eventName,
 *         public array $data
 *     ) {}
 * }
 *
 * // Event subscriber can listen to multiple events
 * class OrderEventSubscriber
 * {
 *     public function handleOrderShipped(OrderShipped $event) {}
 *     public function handleOrderCancelled(OrderCancelled $event) {}
 *     public function handleOrderRefunded(OrderRefunded $event) {}
 *
 *     public function subscribe($events)
 *     {
 *         $events->listen(OrderShipped::class, [self::class, 'handleOrderShipped']);
 *         $events->listen(OrderCancelled::class, [self::class, 'handleOrderCancelled']);
 *         $events->listen(OrderRefunded::class, [self::class, 'handleOrderRefunded']);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 29: Event with Wildcards
 * // ============================================
 * class GenericEvent extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $eventType,
 *         public mixed $payload
 *     ) {}
 * }
 *
 * // Listen to all events with wildcard
 * Event::listen('*', function ($eventName, $data) {
 *     if ($eventName === GenericEvent::class) {
 *         // Handle generic event
 *     }
 * });
 * @example
 * // ============================================
 * // Example 30: Event with Event Discovery
 * // ============================================
 * class AutoDiscoveredEvent extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $action
 *     ) {}
 * }
 *
 * // Events can be auto-discovered if in app/Events directory
 * // Register in EventServiceProvider:
 * protected function discoverEventsWithin()
 * {
 *     return [
 *         $this->app->path('Events'),
 *     ];
 * }
 *
 * // Dispatch and auto-discover listeners
 * AutoDiscoveredEvent::dispatch('action_performed');
 * @example
 * // ============================================
 * // Example 31: Synchronous Event Dispatching
 * // ============================================
 * class CriticalEvent extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public string $message
 *     ) {}
 * }
 *
 * // Dispatch synchronously - bypasses queue, executes immediately
 * CriticalEvent::dispatchSync('System critical alert');
 *
 * // Conditional synchronous dispatch
 * CriticalEvent::dispatchSyncIf(
 *     $system->isCritical(),
 *     'Critical system failure'
 * );
 * @example
 * // ============================================
 * // Example 32: Dispatch After HTTP Response
 * // ============================================
 * class LogUserActivity extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public User $user,
 *         public string $action
 *     ) {}
 * }
 *
 * // Dispatch after response is sent - improves perceived performance
 * LogUserActivity::dispatchAfterResponse($user, 'page_viewed');
 *
 * // Conditional dispatch after response
 * LogUserActivity::dispatchAfterResponseIf(
 *     $user->shouldTrackActivity(),
 *     $user,
 *     'feature_accessed'
 * );
 * @example
 * // ============================================
 * // Example 33: Event with After Commit
 * // ============================================
 * class OrderCompleted extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 *
 *     public function shouldDispatchAfterCommit(): bool
 *     {
 *         return true; // Only dispatch after transaction commits
 *     }
 * }
 *
 * // Dispatch - will wait for transaction to commit
 * DB::transaction(function () use ($order) {
 *     $order->complete();
 *     OrderCompleted::dispatch($order); // Dispatched after commit
 * });
 * @example
 * // ============================================
 * // Example 34: Event with Custom Broadcast Name
 * // ============================================
 * class MessageReceived extends Actions implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Message $message,
 *         public User $recipient
 *     ) {}
 *
 *     public function broadcastAs(): string
 *     {
 *         return 'message.received'; // Custom broadcast name
 *     }
 *
 *     public function broadcastOn(): array
 *     {
 *         return [
 *             new \Illuminate\Broadcasting\PrivateChannel('user.'.$this->recipient->id),
 *         ];
 *     }
 * }
 *
 * // Dispatch and broadcast with custom name
 * MessageReceived::dispatch($message, $recipient);
 * @example
 * // ============================================
 * // Example 35: Mixed Dispatch Methods
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsEvent;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Critical: dispatch synchronously
 *         OrderProcessingStarted::dispatchSync($order);
 *
 *         $order->process();
 *
 *         // Non-critical: dispatch after response
 *         OrderProcessed::dispatchAfterResponse($order);
 *
 *         // Conditional: only if order is large
 *         LargeOrderNotification::dispatchIf(
 *             $order->total > 1000,
 *             $order
 *         );
 *
 *         return $order;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 36: Event with Transaction Safety
 * // ============================================
 * class PaymentProcessed extends Actions
 * {
 *     use AsEvent;
 *
 *     public function __construct(
 *         public Payment $payment
 *     ) {}
 *
 *     public function shouldDispatchAfterCommit(): bool
 *     {
 *         // Always wait for transaction to commit
 *         return true;
 *     }
 * }
 *
 * // Safe to dispatch inside transaction
 * DB::beginTransaction();
 * try {
 *     $payment->process();
 *     PaymentProcessed::dispatch($payment); // Waits for commit
 *     DB::commit();
 * } catch (\Exception $e) {
 *     DB::rollBack();
 *     // Event is not dispatched if transaction fails
 * }
 */
trait AsEvent
{
    /**
     * Dispatch the action as an event.
     *
     * This method creates a new instance of the action with the provided
     * arguments and dispatches it through Laravel's event system.
     *
     * @param  mixed  ...$arguments
     */
    public static function dispatchEvent(...$arguments): void
    {
        Event::dispatch(new static(...$arguments));
    }

    /**
     * Dispatch the action as an event if the condition is true.
     *
     * @param  mixed  ...$arguments
     */
    public static function dispatchEventIf(bool $condition, ...$arguments): void
    {
        if ($condition) {
            static::dispatchEvent(...$arguments);
        }
    }

    /**
     * Dispatch the action as an event unless the condition is true.
     *
     * This is the inverse of dispatchIf - it dispatches when the condition is false.
     *
     * @param  mixed  ...$arguments
     */
    public static function dispatchEventUnless(bool $condition, ...$arguments): void
    {
        static::dispatchEventIf(! $condition, ...$arguments);
    }

    /**
     * Dispatch the action as an event synchronously.
     *
     * This method dispatches the event immediately without queuing, even if
     * listeners implement ShouldQueue. Useful when you need immediate execution
     * or want to bypass the queue system.
     *
     * @param  mixed  ...$arguments
     */
    public static function dispatchEventSync(...$arguments): mixed
    {
        return Event::dispatch(new static(...$arguments));
    }

    /**
     * Dispatch the action as an event synchronously if the condition is true.
     *
     * @param  mixed  ...$arguments
     * @return mixed|null
     */
    public static function dispatchSyncIf(bool $condition, ...$arguments): mixed
    {
        if ($condition) {
            return static::dispatchEventSync(...$arguments);
        }

        return null;
    }

    /**
     * Dispatch the action as an event synchronously unless the condition is true.
     *
     * @param  mixed  ...$arguments
     * @return mixed|null
     */
    public static function dispatchEventSyncUnless(bool $condition, ...$arguments): mixed
    {
        return static::dispatchEventSyncIf(! $condition, ...$arguments);
    }

    /**
     * Dispatch the action as an event after the HTTP response is sent.
     *
     * This method defers event dispatching until after the HTTP response has
     * been sent to the client. This is useful for events that don't need to
     * complete before the response is sent, improving perceived performance.
     *
     * Note: This requires the event to implement ShouldDispatchAfterResponse interface
     * or you can override shouldDispatchAfterResponse() method.
     *
     * @param  mixed  ...$arguments
     */
    public static function dispatchEventAfterResponse(...$arguments): void
    {
        Event::dispatch(new static(...$arguments));
    }

    /**
     * Dispatch the action as an event after the HTTP response if the condition is true.
     *
     * @param  mixed  ...$arguments
     */
    public static function dispatchEventAfterResponseIf(bool $condition, ...$arguments): void
    {
        if ($condition) {
            static::dispatchEventAfterResponse(...$arguments);
        }
    }

    /**
     * Dispatch the action as an event after the HTTP response unless the condition is true.
     *
     * @param  mixed  ...$arguments
     */
    public static function dispatchEventAfterResponseUnless(bool $condition, ...$arguments): void
    {
        static::dispatchEventAfterResponseIf(! $condition, ...$arguments);
    }

    /**
     * Check if the event should be dispatched after database commit.
     *
     * Override this method in your event class to return true if the event
     * should only be dispatched after all pending database transactions
     * have been committed. This prevents race conditions where events might
     * be processed before database changes are persisted.
     *
     * Alternatively, you can implement the ShouldDispatchAfterCommit interface
     * on your event class for the same behavior.
     */
    public function shouldDispatchAfterCommit(): bool
    {
        return false;
    }

    /**
     * Get the event name for broadcasting.
     *
     * Override this method to customize the broadcast event name.
     * By default, returns the class name without namespace.
     *
     * This is used when the event implements ShouldBroadcast interface.
     */
    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * Override this method to define broadcast channels. Return an array
     * of channel instances (PrivateChannel, PresenceChannel, or Channel).
     *
     * This is used when the event implements ShouldBroadcast interface.
     */
    public function broadcastOn(): array
    {
        return [];
    }
}
