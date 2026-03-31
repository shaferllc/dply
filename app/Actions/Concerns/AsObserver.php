<?php

namespace App\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Allows actions to observe Laravel model events.
 *
 * This trait enables actions to be registered as observers for Eloquent models,
 * allowing them to respond to model events like created, updated, deleted, etc.
 * Actions using this trait can be registered to observe model events using
 * the static `observe()` method.
 *
 * How it works:
 * - Actions using AsObserver can be registered as observers for models
 * - The `observe()` method registers the action to listen to model events
 * - When model events fire, the action's event methods are called
 * - Supports both specific event methods (created, updated, etc.) and generic handle()
 * - Events are registered via Laravel's Event system
 *
 * Benefits:
 * - Use actions as model observers
 * - Clean separation of concerns
 * - Reusable observer logic
 * - Easy to test
 * - Works with Laravel's observer pattern
 * - Supports all Eloquent model events
 *
 * Note: This is NOT a decorator pattern. This is a utility trait that provides
 * observer registration capabilities. It doesn't intercept action execution
 * or wrap actions - it allows actions to BE observers for models.
 *
 * Supported Events:
 * - created: When a model is created
 * - updated: When a model is updated
 * - deleted: When a model is deleted
 * - restored: When a model is restored (soft deletes)
 * - saving: Before a model is saved
 * - saved: After a model is saved
 * - creating: Before a model is created
 * - updating: Before a model is updated
 * - deleting: Before a model is deleted
 * - forceDeleted: When a model is force deleted
 *
 * @example
 * // ============================================
 * // Example 1: Basic Observer with Generic Handle
 * // ============================================
 * class LogUserActivity extends Actions
 * {
 *     use AsObserver;
 *
 *     public function handle(User $user, string $event): void
 *     {
 *         ActivityLog::create([
 *             'user_id' => $user->id,
 *             'event' => $event,
 *             'timestamp' => now(),
 *         ]);
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated', 'deleted'];
 *     }
 * }
 *
 * // Register in ServiceProvider:
 * LogUserActivity::observe(User::class);
 *
 * // Usage:
 * $user = User::create(['name' => 'John']);
 * // Automatically calls LogUserActivity::handle($user, 'created')
 * @example
 * // ============================================
 * // Example 2: Observer with Specific Event Methods
 * // ============================================
 * class TrackUserChanges extends Actions
 * {
 *     use AsObserver;
 *
 *     public function created(User $user): void
 *     {
 *         \Log::info("User created: {$user->id}");
 *         // Send welcome email
 *         Mail::to($user)->send(new WelcomeEmail($user));
 *     }
 *
 *     public function updated(User $user): void
 *     {
 *         \Log::info("User updated: {$user->id}");
 *         // Sync with external service
 *         ExternalService::syncUser($user);
 *     }
 *
 *     public function deleted(User $user): void
 *     {
 *         \Log::info("User deleted: {$user->id}");
 *         // Cleanup related data
 *         $user->posts()->delete();
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated', 'deleted'];
 *     }
 * }
 *
 * // Register:
 * TrackUserChanges::observe(User::class);
 * @example
 * // ============================================
 * // Example 3: Observer with All Default Events
 * // ============================================
 * class ComprehensiveObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     // Uses default events: ['created', 'updated', 'deleted', 'saved', 'restored']
 *     public function handle($model, string $event): void
 *     {
 *         \Log::info("Model {$event}: ".get_class($model)." #{$model->id}");
 *     }
 * }
 *
 * // Register:
 * ComprehensiveObserver::observe(User::class);
 * // Observes: created, updated, deleted, saved, restored
 * @example
 * // ============================================
 * // Example 4: Observer with Soft Delete Events
 * // ============================================
 * class SoftDeleteObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function deleted(User $user): void
 *     {
 *         // User was soft deleted
 *         \Log::info("User soft deleted: {$user->id}");
 *     }
 *
 *     public function restored(User $user): void
 *     {
 *         // User was restored
 *         \Log::info("User restored: {$user->id}");
 *         // Reactivate user account
 *         $user->update(['active' => true]);
 *     }
 *
 *     public function forceDeleted(User $user): void
 *     {
 *         // User was permanently deleted
 *         \Log::info("User permanently deleted: {$user->id}");
 *         // Cleanup external data
 *         ExternalService::deleteUser($user->id);
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['deleted', 'restored', 'forceDeleted'];
 *     }
 * }
 *
 * // Register:
 * SoftDeleteObserver::observe(User::class);
 * @example
 * // ============================================
 * // Example 5: Observer with Before/After Events
 * // ============================================
 * class ValidationObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function creating(Order $order): void
 *     {
 *         // Validate before creation
 *         if ($order->total < 0) {
 *             throw new \Exception('Order total cannot be negative');
 *         }
 *     }
 *
 *     public function updating(Order $order): void
 *     {
 *         // Validate before update
 *         if ($order->isDirty('status') && $order->status === 'cancelled') {
 *             // Prevent cancellation if order is shipped
 *             if ($order->getOriginal('status') === 'shipped') {
 *                 throw new \Exception('Cannot cancel shipped order');
 *             }
 *         }
 *     }
 *
 *     public function saved(Order $order): void
 *     {
 *         // After save (both create and update)
 *         \Log::info("Order saved: {$order->id}");
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['creating', 'updating', 'saved'];
 *     }
 * }
 *
 * // Register:
 * ValidationObserver::observe(Order::class);
 * @example
 * // ============================================
 * // Example 6: Observer with Multiple Models
 * // ============================================
 * class ActivityLogger extends Actions
 * {
 *     use AsObserver;
 *
 *     public function handle($model, string $event): void
 *     {
 *         ActivityLog::create([
 *             'model_type' => get_class($model),
 *             'model_id' => $model->id,
 *             'event' => $event,
 *             'user_id' => auth()->id(),
 *             'timestamp' => now(),
 *         ]);
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated', 'deleted'];
 *     }
 * }
 *
 * // Register for multiple models:
 * ActivityLogger::observe(User::class);
 * ActivityLogger::observe(Post::class);
 * ActivityLogger::observe(Comment::class);
 * // All models are observed with the same action
 * @example
 * // ============================================
 * // Example 7: Observer with Conditional Logic
 * // ============================================
 * class ConditionalObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function created(Post $post): void
 *     {
 *         // Only notify if post is published
 *         if ($post->status === 'published') {
 *             Notification::send($post->author->followers, new PostPublished($post));
 *         }
 *     }
 *
 *     public function updated(Post $post): void
 *     {
 *         // Only sync if specific fields changed
 *         if ($post->wasChanged(['title', 'content'])) {
 *             ExternalService::syncPost($post);
 *         }
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated'];
 *     }
 * }
 *
 * // Register:
 * ConditionalObserver::observe(Post::class);
 * @example
 * // ============================================
 * // Example 8: Observer with Relationship Updates
 * // ============================================
 * class UpdateRelatedData extends Actions
 * {
 *     use AsObserver;
 *
 *     public function created(Comment $comment): void
 *     {
 *         // Update post comment count
 *         $comment->post->increment('comment_count');
 *     }
 *
 *     public function deleted(Comment $comment): void
 *     {
 *         // Decrement post comment count
 *         $comment->post->decrement('comment_count');
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'deleted'];
 *     }
 * }
 *
 * // Register:
 * UpdateRelatedData::observe(Comment::class);
 * @example
 * // ============================================
 * // Example 9: Observer with Cache Invalidation
 * // ============================================
 * class CacheInvalidationObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function updated(Product $product): void
 *     {
 *         // Invalidate cache when product is updated
 *         Cache::forget("product.{$product->id}");
 *         Cache::forget('products.list');
 *         Cache::tags(['products'])->flush();
 *     }
 *
 *     public function deleted(Product $product): void
 *     {
 *         // Invalidate cache when product is deleted
 *         Cache::forget("product.{$product->id}");
 *         Cache::forget('products.list');
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['updated', 'deleted'];
 *     }
 * }
 *
 * // Register:
 * CacheInvalidationObserver::observe(Product::class);
 * @example
 * // ============================================
 * // Example 10: Observer with External API Sync
 * // ============================================
 * class ExternalSyncObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function created(User $user): void
 *     {
 *         // Sync new user to external service
 *         ExternalUserService::create([
 *             'id' => $user->id,
 *             'name' => $user->name,
 *             'email' => $user->email,
 *         ]);
 *     }
 *
 *     public function updated(User $user): void
 *     {
 *         // Sync updated user to external service
 *         if ($user->wasChanged(['name', 'email'])) {
 *             ExternalUserService::update($user->id, [
 *                 'name' => $user->name,
 *                 'email' => $user->email,
 *             ]);
 *         }
 *     }
 *
 *     public function deleted(User $user): void
 *     {
 *         // Remove user from external service
 *         ExternalUserService::delete($user->id);
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated', 'deleted'];
 *     }
 * }
 *
 * // Register:
 * ExternalSyncObserver::observe(User::class);
 * @example
 * // ============================================
 * // Example 11: Observer with Queue Jobs
 * // ============================================
 * class QueuedObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function created(Order $order): void
 *     {
 *         // Dispatch job to process order
 *         ProcessOrderJob::dispatch($order);
 *     }
 *
 *     public function updated(Order $order): void
 *     {
 *         // Dispatch job if status changed
 *         if ($order->wasChanged('status')) {
 *             NotifyOrderStatusChangeJob::dispatch($order);
 *         }
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated'];
 *     }
 * }
 *
 * // Register:
 * QueuedObserver::observe(Order::class);
 * @example
 * // ============================================
 * // Example 12: Observer with Audit Trail
 * // ============================================
 * class AuditTrailObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function handle($model, string $event): void
 *     {
 *         AuditLog::create([
 *             'user_id' => auth()->id(),
 *             'model_type' => get_class($model),
 *             'model_id' => $model->id,
 *             'event' => $event,
 *             'old_values' => $model->getOriginal(),
 *             'new_values' => $model->getAttributes(),
 *             'ip_address' => request()->ip(),
 *             'user_agent' => request()->userAgent(),
 *         ]);
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated', 'deleted'];
 *     }
 * }
 *
 * // Register:
 * AuditTrailObserver::observe(User::class);
 * AuditTrailObserver::observe(Order::class);
 * @example
 * // ============================================
 * // Example 13: Observer with Email Notifications
 * // ============================================
 * class EmailNotificationObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function created(Ticket $ticket): void
 *     {
 *         // Notify assignee
 *         if ($ticket->assignee) {
 *             Mail::to($ticket->assignee)->send(new TicketAssigned($ticket));
 *         }
 *     }
 *
 *     public function updated(Ticket $ticket): void
 *     {
 *         // Notify if status changed
 *         if ($ticket->wasChanged('status')) {
 *             Mail::to($ticket->requester)->send(new TicketStatusChanged($ticket));
 *         }
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['created', 'updated'];
 *     }
 * }
 *
 * // Register:
 * EmailNotificationObserver::observe(Ticket::class);
 * @example
 * // ============================================
 * // Example 14: Observer Registration in ServiceProvider
 * // ============================================
 * class AppServiceProvider extends ServiceProvider
 * {
 *     public function boot(): void
 *     {
 *         // Register observers
 *         LogUserActivity::observe(User::class);
 *         TrackOrderChanges::observe(Order::class);
 *         CacheInvalidationObserver::observe(Product::class);
 *     }
 * }
 *
 * // Or register in model's boot method:
 * class User extends Model
 * {
 *     protected static function boot()
 *     {
 *         parent::boot();
 *         static::observe(LogUserActivity::class);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - E-commerce Order Observer
 * // ============================================
 * class OrderLifecycleObserver extends Actions
 * {
 *     use AsObserver;
 *
 *     public function creating(Order $order): void
 *     {
 *         // Set order number before creation
 *         $order->order_number = $this->generateOrderNumber();
 *     }
 *
 *     public function created(Order $order): void
 *     {
 *         // Send order confirmation
 *         Mail::to($order->customer)->send(new OrderConfirmation($order));
 *
 *         // Update inventory
 *         foreach ($order->items as $item) {
 *             $item->product->decrement('stock', $item->quantity);
 *         }
 *
 *         // Create payment record
 *         Payment::create([
 *             'order_id' => $order->id,
 *             'amount' => $order->total,
 *             'status' => 'pending',
 *         ]);
 *     }
 *
 *     public function updated(Order $order): void
 *     {
 *         // If order was shipped
 *         if ($order->wasChanged('status') && $order->status === 'shipped') {
 *             Mail::to($order->customer)->send(new OrderShipped($order));
 *             TrackingService::createTracking($order);
 *         }
 *
 *         // If order was cancelled
 *         if ($order->wasChanged('status') && $order->status === 'cancelled') {
 *             // Restore inventory
 *             foreach ($order->items as $item) {
 *                 $item->product->increment('stock', $item->quantity);
 *             }
 *         }
 *     }
 *
 *     public function deleted(Order $order): void
 *     {
 *         // Cleanup related data
 *         $order->payments()->delete();
 *         $order->shipments()->delete();
 *     }
 *
 *     public function getObservedEvents(): array
 *     {
 *         return ['creating', 'created', 'updated', 'deleted'];
 *     }
 *
 *     protected function generateOrderNumber(): string
 *     {
 *         return 'ORD-'.strtoupper(uniqid());
 *     }
 * }
 *
 * // Register:
 * OrderLifecycleObserver::observe(Order::class);
 *
 * @see Model
 * @see Event
 */
trait AsObserver
{
    /**
     * Register this action as an observer for a model.
     *
     * @param  string  $modelClass  The model class to observe
     */
    public static function observe(string $modelClass): void
    {
        $instance = static::make();
        $events = $instance->getObservedEvents();

        foreach ($events as $event) {
            static::registerObserver($modelClass, $event);
        }
    }

    /**
     * Register observer for a specific event.
     *
     * @param  string  $modelClass  The model class to observe
     * @param  string  $event  The event to observe
     */
    protected static function registerObserver(string $modelClass, string $event): void
    {
        $eventName = "eloquent.{$event}: {$modelClass}";

        Event::listen($eventName, function ($model) use ($event) {
            $instance = static::make();

            // Call specific event method if it exists (e.g., created(), updated())
            if (method_exists($instance, $event)) {
                $instance->{$event}($model);
            } elseif (method_exists($instance, 'handle')) {
                // Fallback to generic handle() method
                call_user_func_array([$instance, 'handle'], [$model, $event]);
            }
        });
    }

    /**
     * Get the events this observer should listen to.
     * Override this method to customize observed events.
     */
    protected function getObservedEvents(): array
    {
        // This method should be overridden in the action class
        // If not overridden, return default events
        // Note: We can't easily detect if it's overridden here without reflection complexity
        // So we return defaults and rely on the action class to override if needed
        return ['created', 'updated', 'deleted', 'saved', 'restored'];
    }
}
