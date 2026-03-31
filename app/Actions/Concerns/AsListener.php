<?php

namespace App\Actions\Concerns;

/**
 * Allows actions to listen to Laravel events.
 *
 * This trait is a marker that enables automatic event listener functionality via ListenerDecorator.
 * When an action uses AsListener, ListenerDesignPattern recognizes it and
 * ActionManager wraps the action with ListenerDecorator.
 *
 * How it works:
 * 1. Action uses AsListener trait (marker)
 * 2. ListenerDesignPattern recognizes the trait
 * 3. ActionManager wraps action with ListenerDecorator
 * 4. When an event is dispatched, Laravel calls the listener:
 *    - ListenerDecorator tries asListener() method first
 *    - Falls back to handle() method
 *    - Falls back to __invoke() method
 *    - Uses dependency injection to resolve method parameters
 *
 * Benefits:
 * - Actions can listen to Laravel events
 * - Automatic dependency injection for event parameters
 * - Support for queued listeners
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - Flexible method resolution (asListener, handle, or __invoke)
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ListenerDecorator, which automatically wraps actions and adds listener functionality.
 * This follows the same pattern as AsLogger, AsMetrics, AsLock, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `asListener($event)` method for custom event handling
 * - Implement `handle($event)` method (default if asListener not found)
 * - Implement `__invoke($event)` method (fallback)
 * - Implement `shouldQueue($event)` method to control queuing (default: true)
 *
 * Auto-Discovery:
 * Action listeners are automatically discovered and registered by default.
 * The system scans for all actions using AsListener and registers them
 * based on their method signatures (the event class in the first parameter).
 *
 * To disable auto-discovery, set in config/actions.php:
 * 'auto_discover_listeners' => false
 *
 * To customize scan paths, set in config/actions.php:
 * 'listener_paths' => [app_path('Actions'), app_path('Modules')]
 *
 * You can also manually discover listeners:
 * \App\Actions\Helpers\ListenerAutoDiscovery::discoverAndRegister();
 *
 * Or get the mappings without registering:
 * $mappings = \App\Actions\Helpers\ListenerAutoDiscovery::discover();
 *
 * @example
 * // ============================================
 * // Example 1: Basic Event Listener
 * // ============================================
 * class SendWelcomeEmail extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(UserRegistered $event): void
 *     {
 *         Mail::to($event->user)->send(new WelcomeEmail);
 *     }
 * }
 *
 * // Auto-registered! No need to manually register in EventServiceProvider.
 * // The system automatically discovers this listener based on the UserRegistered
 * // type hint in the handle() method.
 *
 * // Optional: Manually register in EventServiceProvider if auto-discovery is disabled:
 * // protected $listen = [
 * //     UserRegistered::class => [SendWelcomeEmail::class],
 * // ];
 * @example
 * // ============================================
 * // Example 2: Using asListener Method
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsListener;
 *
 *     public function asListener(OrderCreated $event): void
 *     {
 *         // Custom listener logic
 *         $this->handle($event);
 *     }
 *
 *     protected function handle(OrderCreated $event): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * // Auto-registered based on OrderCreated type hint
 * @example
 * // ============================================
 * // Example 3: Queued Listener
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(NotificationEvent $event): void
 *     {
 *         // Send notification (runs in queue)
 *     }
 *
 *     public function shouldQueue(NotificationEvent $event): bool
 *     {
 *         return true; // Queue this listener
 *     }
 * }
 *
 * // Auto-registered based on NotificationEvent type hint
 * @example
 * // ============================================
 * // Example 4: Synchronous Listener
 * // ============================================
 * class UpdateCache extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(CacheInvalidated $event): void
 *     {
 *         // Update cache immediately (not queued)
 *     }
 *
 *     public function shouldQueue(CacheInvalidated $event): bool
 *     {
 *         return false; // Run synchronously
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Conditional Queuing
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(PaymentReceived $event): void
 *     {
 *         // Process payment
 *     }
 *
 *     public function shouldQueue(PaymentReceived $event): bool
 *     {
 *         // Only queue large payments
 *         return $event->amount > 1000;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 6: Multiple Event Parameters
 * // ============================================
 * class LogActivity extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(User $user, string $action, array $data): void
 *     {
 *         ActivityLog::create([
 *             'user_id' => $user->id,
 *             'action' => $action,
 *             'data' => $data,
 *         ]);
 *     }
 * }
 *
 * // Event class:
 * class ActivityEvent
 * {
 *     public function __construct(
 *         public User $user,
 *         public string $action,
 *         public array $data
 *     ) {}
 * }
 *
 * // Auto-registered based on ActivityEvent type hint
 * @example
 * // ============================================
 * // Example 7: Combining with Other Decorators
 * // ============================================
 * class ProcessTransaction extends Actions
 * {
 *     use AsListener;
 *     use AsTransaction;
 *     use AsLogger;
 *
 *     public function handle(TransactionCreated $event): void
 *     {
 *         // Process transaction
 *     }
 * }
 *
 * // All decorators work together:
 * // - ListenerDecorator handles event listening
 * // - TransactionDecorator ensures database consistency
 * // - LoggerDecorator tracks execution
 * @example
 * // ============================================
 * // Example 8: Using __invoke Method
 * // ============================================
 * class UpdateStatistics extends Actions
 * {
 *     use AsListener;
 *
 *     public function __invoke(StatisticsEvent $event): void
 *     {
 *         // Update statistics
 *     }
 * }
 *
 * // Auto-registered based on StatisticsEvent type hint
 * @example
 * // ============================================
 * // Example 9: Email Notifications
 * // ============================================
 * class SendOrderConfirmation extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(OrderPlaced $event): void
 *     {
 *         Mail::to($event->order->user)
 *             ->send(new OrderConfirmation($event->order));
 *     }
 *
 *     public function shouldQueue(OrderPlaced $event): bool
 *     {
 *         return true; // Queue email sending
 *     }
 * }
 *
 * // Auto-registered based on OrderPlaced type hint
 * @example
 * // ============================================
 * // Example 10: Database Updates
 * // ============================================
 * class UpdateUserLastSeen extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(UserLoggedIn $event): void
 *     {
 *         $event->user->update([
 *             'last_seen_at' => now(),
 *         ]);
 *     }
 *
 *     public function shouldQueue(UserLoggedIn $event): bool
 *     {
 *         return false; // Run immediately
 *     }
 * }
 * @example
 * // ============================================
 * // Example 11: Cache Invalidation
 * // ============================================
 * class InvalidateUserCache extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(UserUpdated $event): void
 *     {
 *         Cache::forget("user:{$event->user->id}");
 *         Cache::forget("user:{$event->user->id}:profile");
 *     }
 * }
 *
 * // Auto-registered based on UserUpdated type hint
 * @example
 * // ============================================
 * // Example 12: Webhook Notifications
 * // ============================================
 * class SendWebhook extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(WebhookEvent $event): void
 *     {
 *         Http::post($event->url, $event->payload);
 *     }
 *
 *     public function shouldQueue(WebhookEvent $event): bool
 *     {
 *         return true; // Queue webhook calls
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Audit Logging
 * // ============================================
 * class LogAuditTrail extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(AuditEvent $event): void
 *     {
 *         AuditLog::create([
 *             'user_id' => $event->user->id,
 *             'action' => $event->action,
 *             'model_type' => $event->model::class,
 *             'model_id' => $event->model->id,
 *             'changes' => $event->changes,
 *         ]);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: Real-time Notifications
 * // ============================================
 * class BroadcastNotification extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(NotificationEvent $event): void
 *     {
 *         broadcast(new NotificationBroadcast($event->user, $event->message))
 *             ->toOthers();
 *     }
 *
 *     public function shouldQueue(NotificationEvent $event): bool
 *     {
 *         return true; // Queue broadcasting
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: File Processing
 * // ============================================
 * class ProcessUploadedFile extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(FileUploaded $event): void
 *     {
 *         // Process uploaded file
 *         $processor = new FileProcessor($event->file);
 *         $processor->process();
 *     }
 *
 *     public function shouldQueue(FileUploaded $event): bool
 *     {
 *         // Queue large files, process small ones immediately
 *         return $event->file->getSize() > 1024 * 1024; // > 1MB
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Search Index Updates
 * // ============================================
 * class UpdateSearchIndex extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(ProductUpdated $event): void
 *     {
 *         // Update search index
 *         Search::index('products')->update($event->product);
 *     }
 *
 *     public function shouldQueue(ProductUpdated $event): bool
 *     {
 *         return true; // Always queue search index updates
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Social Media Integration
 * // ============================================
 * class PostToSocialMedia extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(ContentPublished $event): void
 *     {
 *         if ($event->content->shouldPostToSocial()) {
 *             SocialMedia::post($event->content);
 *         }
 *     }
 *
 *     public function shouldQueue(ContentPublished $event): bool
 *     {
 *         return true; // Queue social media posts
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Analytics Tracking
 * // ============================================
 * class TrackEvent extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(AnalyticsEvent $event): void
 *     {
 *         Analytics::track($event->name, $event->properties);
 *     }
 *
 *     public function shouldQueue(AnalyticsEvent $event): bool
 *     {
 *         return false; // Track immediately for real-time analytics
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: Multi-Listener Event
 * // ============================================
 * class SendWelcomeEmail extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(UserRegistered $event): void
 *     {
 *         Mail::to($event->user)->send(new WelcomeEmail);
 *     }
 * }
 *
 * class CreateUserProfile extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(UserRegistered $event): void
 *     {
 *         UserProfile::create(['user_id' => $event->user->id]);
 *     }
 * }
 *
 * // Both listeners are auto-registered for UserRegistered event.
 * // Multiple listeners for the same event are automatically discovered.
 * @example
 * // ============================================
 * // Example 20: Error Handling in Listeners
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(PaymentReceived $event): void
 *     {
 *         try {
 *             // Process payment
 *         } catch (\Exception $e) {
 *             // Log error and notify admins
 *             logger()->error('Payment processing failed', [
 *                 'event' => $event,
 *                 'error' => $e->getMessage(),
 *             ]);
 *
 *             Notification::route('mail', 'admin@example.com')
 *                 ->notify(new PaymentProcessingFailed($event, $e));
 *
 *             throw $e; // Re-throw to mark job as failed
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 21: Wildcard Event Listeners
 * // ============================================
 * class LogAllEvents extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle($event): void
 *     {
 *         // Log all events (using wildcard listener)
 *         logger()->info('Event fired', [
 *             'event' => get_class($event),
 *             'data' => $event,
 *         ]);
 *     }
 * }
 *
 * // Note: Wildcard listeners must still be manually registered in EventServiceProvider:
 * // protected $listen = [
 * //     '*' => [LogAllEvents::class],
 * // ];
 * @example
 * // ============================================
 * // Example 22: Subscriber Pattern
 * // ============================================
 * class UserEventSubscriber extends Actions
 * {
 *     use AsListener;
 *
 *     public function handleUserRegistered(UserRegistered $event): void
 *     {
 *         // Handle user registered
 *     }
 *
 *     public function handleUserUpdated(UserUpdated $event): void
 *     {
 *         // Handle user updated
 *     }
 * }
 *
 * // Note: Subscribers must still be manually registered in EventServiceProvider:
 * // protected $subscribe = [
 * //     UserEventSubscriber::class,
 * // ];
 * @example
 * // ============================================
 * // Example 23: Event with Dependencies
 * // ============================================
 * class SendEmailWithService extends Actions
 * {
 *     use AsListener;
 *
 *     public function __construct(
 *         protected EmailService $emailService
 *     ) {}
 *
 *     public function handle(EmailEvent $event): void
 *     {
 *         $this->emailService->send($event->to, $event->message);
 *     }
 * }
 *
 * // Dependencies are automatically injected by Laravel's container
 * @example
 * // ============================================
 * // Example 24: Conditional Listener Execution
 * // ============================================
 * class ConditionalNotification extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(NotificationEvent $event): void
 *     {
 *         // Only send if user has notifications enabled
 *         if ($event->user->notifications_enabled) {
 *             Mail::to($event->user)->send(new Notification($event));
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 25: Chained Event Processing
 * // ============================================
 * class ProcessOrderChain extends Actions
 * {
 *     use AsListener;
 *
 *     public function handle(OrderCreated $event): void
 *     {
 *         // Process order
 *         $this->validateOrder($event->order);
 *         $this->reserveInventory($event->order);
 *         $this->calculateShipping($event->order);
 *
 *         // Dispatch next event in chain
 *         event(new OrderProcessed($event->order));
 *     }
 *
 *     protected function validateOrder($order): void
 *     {
 *         // Validation logic
 *     }
 *
 *     protected function reserveInventory($order): void
 *     {
 *         // Inventory logic
 *     }
 *
 *     protected function calculateShipping($order): void
 *     {
 *         // Shipping logic
 *     }
 * }
 * @example
 * // ============================================
 * // Example 26: Auto-Discovery Configuration
 * // ============================================
 * // Create config/actions.php to configure auto-discovery:
 * //
 * // <?php
 * // return [
 * //     'auto_discover_listeners' => true, // Enable/disable auto-discovery
 * //     'listener_paths' => [
 * //         app_path('Actions'),
 * //         app_path('Modules'),
 * //     ],
 * // ];
 * //
 * // Or manually discover and register:
 * // \App\Actions\Helpers\ListenerAutoDiscovery::discoverAndRegister();
 * //
 * // Or get mappings without registering:
 * // $mappings = \App\Actions\Helpers\ListenerAutoDiscovery::discover();
 * // // Returns: ['EventClass' => ['ListenerClass1', 'ListenerClass2']]
 */
trait AsListener
{
    // This is a marker trait - the actual listener functionality is handled by ListenerDecorator
    // via the ListenerDesignPattern and ActionManager
}
