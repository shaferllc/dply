<?php

namespace App\Actions\Concerns;

use App\Actions\Decorators\ObservableDecorator;
use App\Actions\DesignPatterns\ObservableDesignPattern;
use Illuminate\Support\Facades\Event;

/**
 * Makes actions observable for monitoring, debugging, and logging.
 *
 * Provides automatic event firing for action execution lifecycle, allowing you to
 * monitor, log, and debug action execution. Fires events for started, completed,
 * and failed states with execution duration tracking.
 *
 * How it works:
 * - ObservableDesignPattern recognizes actions using AsObservable
 * - ActionManager wraps the action with ObservableDecorator
 * - When handle() is called, the decorator:
 *    - Fires 'action.started' event with action class and arguments
 *    - Records start time for duration tracking
 *    - Executes the action
 *    - Fires 'action.completed' event with result and duration
 *    - On exception, fires 'action.failed' event with exception and duration
 *    - Returns the result (or re-throws exception)
 *
 * Benefits:
 * - Automatic event firing for action lifecycle
 * - Execution duration tracking
 * - Exception tracking and logging
 * - Performance monitoring
 * - Debugging support
 * - Configurable event firing
 * - Works with Laravel's Event system
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ObservableDecorator, which automatically wraps actions and fires events.
 * This follows the same pattern as AsOAuth, AsPermission, and other
 * decorator-based concerns.
 *
 * Events Fired:
 * - `action.started`: When action execution begins
 *   - Data: ['action' => class name, 'arguments' => array]
 * - `action.completed`: When action completes successfully
 *   - Data: ['action' => class name, 'result' => mixed, 'duration' => float (seconds)]
 * - `action.failed`: When action throws an exception
 *   - Data: ['action' => class name, 'exception' => Throwable, 'duration' => float (seconds)]
 *
 * Configuration:
 * - Set `actions.observable.enabled` in config to enable/disable globally
 * - Override `shouldFireEvents()` method to customize per-action
 *
 * @example
 * // ============================================
 * // Example 1: Basic Observable Action
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Processing logic
 *         $order->process();
 *     }
 * }
 *
 * // Listen to events:
 * Event::listen('action.started', function ($data) {
 *     \Log::info("Action started: {$data['action']}", $data);
 * });
 *
 * Event::listen('action.completed', function ($data) {
 *     \Log::info("Action completed: {$data['action']} in {$data['duration']}s", $data);
 * });
 *
 * // Usage:
 * ProcessOrder::run($order);
 * // Fires: action.started, action.completed
 * @example
 * // ============================================
 * // Example 2: Monitoring Action Performance
 * // ============================================
 * class SlowOperation extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(array $data): array
 *     {
 *         // Slow operation
 *         sleep(2);
 *         return $data;
 *     }
 * }
 *
 * // Monitor slow actions:
 * Event::listen('action.completed', function ($data) {
 *     if ($data['duration'] > 1.0) {
 *         \Log::warning("Slow action detected", [
 *             'action' => $data['action'],
 *             'duration' => $data['duration'],
 *         ]);
 *     }
 * });
 *
 * // Usage:
 * SlowOperation::run(['data' => 'test']);
 * // Logs warning if execution takes more than 1 second
 * @example
 * // ============================================
 * // Example 3: Error Tracking
 * // ============================================
 * class RiskyOperation extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(string $data): void
 *     {
 *         // Operation that might fail
 *         if (random_int(1, 10) === 1) {
 *             throw new \Exception('Random failure');
 *         }
 *     }
 * }
 *
 * // Track failures:
 * Event::listen('action.failed', function ($data) {
 *     \Log::error("Action failed", [
 *         'action' => $data['action'],
 *         'exception' => $data['exception']->getMessage(),
 *         'duration' => $data['duration'],
 *     ]);
 *
 *     // Send to error tracking service
 *     ErrorTrackingService::captureException($data['exception'], [
 *         'action' => $data['action'],
 *     ]);
 * });
 *
 * // Usage:
 * try {
 *     RiskyOperation::run('data');
 * } catch (\Exception $e) {
 *     // Exception is logged and tracked
 * }
 * @example
 * // ============================================
 * // Example 4: Conditional Event Firing
 * // ============================================
 * class ConditionalObservable extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 *
 *     public function shouldFireEvents(): bool
 *     {
 *         // Only fire events in development
 *         return app()->environment('local', 'staging');
 *     }
 * }
 *
 * // Usage:
 * ConditionalObservable::run();
 * // Events only fire in local/staging environments
 * @example
 * // ============================================
 * // Example 5: Using Properties for Configuration
 * // ============================================
 * class ConfigurableObservable extends Actions
 * {
 *     use AsObservable;
 *
 *     // Configure via properties
 *     public bool $shouldFireEvents = true;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Usage:
 * $action = ConfigurableObservable::make();
 * $action->shouldFireEvents = false;
 * $action->handle();
 * // Events won't fire
 * @example
 * // ============================================
 * // Example 6: Performance Metrics Collection
 * // ============================================
 * class MetricsCollector extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Collect metrics:
 * Event::listen('action.completed', function ($data) {
 *     MetricsService::record('action.duration', $data['duration'], [
 *         'action' => $data['action'],
 *     ]);
 *
 *     MetricsService::increment('action.executions', [
 *         'action' => $data['action'],
 *     ]);
 * });
 *
 * // Usage:
 * MetricsCollector::run();
 * // Metrics are automatically collected
 * @example
 * // ============================================
 * // Example 7: Action Audit Logging
 * // ============================================
 * class AuditableAction extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(User $user, array $data): void
 *     {
 *         // Operation that should be audited
 *     }
 * }
 *
 * // Audit logging:
 * Event::listen('action.started', function ($data) {
 *     AuditLog::create([
 *         'user_id' => auth()->id(),
 *         'action' => $data['action'],
 *         'arguments' => $data['arguments'],
 *         'status' => 'started',
 *         'timestamp' => now(),
 *     ]);
 * });
 *
 * Event::listen('action.completed', function ($data) {
 *     AuditLog::where('action', $data['action'])
 *         ->latest()
 *         ->first()
 *         ->update([
 *             'status' => 'completed',
 *             'duration' => $data['duration'],
 *         ]);
 * });
 *
 * // Usage:
 * AuditableAction::run($user, ['data' => 'value']);
 * // Creates audit log entries
 * @example
 * // ============================================
 * // Example 8: Real-Time Monitoring Dashboard
 * // ============================================
 * class MonitoredAction extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Broadcast to dashboard:
 * Event::listen('action.started', function ($data) {
 *     broadcast(new ActionStarted($data))->toOthers();
 * });
 *
 * Event::listen('action.completed', function ($data) {
 *     broadcast(new ActionCompleted($data))->toOthers();
 * });
 *
 * // Usage:
 * MonitoredAction::run();
 * // Events are broadcast to real-time dashboard
 * @example
 * // ============================================
 * // Example 9: Action Dependency Tracking
 * // ============================================
 * class DependentAction extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(): void
 *     {
 *         // Operation that depends on other actions
 *     }
 * }
 *
 * // Track action dependencies:
 * $actionStack = [];
 *
 * Event::listen('action.started', function ($data) use (&$actionStack) {
 *     $actionStack[] = $data['action'];
 *     \Log::info('Action stack', ['stack' => $actionStack]);
 * });
 *
 * Event::listen('action.completed', function ($data) use (&$actionStack) {
 *     array_pop($actionStack);
 * });
 *
 * // Usage:
 * DependentAction::run();
 * // Tracks action execution stack
 * @example
 * // ============================================
 * // Example 10: Rate Limiting Based on Performance
 * // ============================================
 * class RateLimitedAction extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Rate limit slow actions:
 * Event::listen('action.completed', function ($data) {
 *     if ($data['duration'] > 5.0) {
 *         // Action took more than 5 seconds
 *         RateLimiter::hit("slow_action:{$data['action']}", 60);
 *
 *         if (RateLimiter::tooManyAttempts("slow_action:{$data['action']}", 5)) {
 *             \Log::warning("Action rate limited due to slow performance", [
 *                 'action' => $data['action'],
 *             ]);
 *         }
 *     }
 * });
 * @example
 * // ============================================
 * // Example 11: Action Result Caching
 * // ============================================
 * class CacheableAction extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(string $key): array
 *     {
 *         // Expensive operation
 *         return expensiveOperation($key);
 *     }
 * }
 *
 * // Cache results:
 * Event::listen('action.completed', function ($data) {
 *     if (isset($data['arguments'][0])) {
 *         $key = "action_result:{$data['action']}:{$data['arguments'][0]}";
 *         Cache::put($key, $data['result'], now()->addHours(1));
 *     }
 * });
 *
 * // Usage:
 * CacheableAction::run('key1');
 * // Result is cached for future use
 * @example
 * // ============================================
 * // Example 12: Action Execution Queue
 * // ============================================
 * class QueuedAction extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Queue action execution for analysis:
 * Event::listen('action.completed', function ($data) {
 *     ActionExecutionQueue::push([
 *         'action' => $data['action'],
 *         'duration' => $data['duration'],
 *         'timestamp' => now(),
 *     ]);
 * });
 *
 * // Usage:
 * QueuedAction::run();
 * // Execution data is queued for analysis
 * @example
 * // ============================================
 * // Example 13: Combining with Other Decorators
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsObservable;
 *     use AsRetry;
 *     use AsTimeout;
 *     use AsValidated;
 *
 *     public function handle(array $data): void
 *     {
 *         // Operation with observability, retry, timeout, and validation
 *     }
 * }
 *
 * // Usage:
 * ComprehensiveAction::run(['key' => 'value']);
 * // Combines observability with other decorators
 * // Events fire for each retry attempt
 * @example
 * // ============================================
 * // Example 14: Action Execution Profiling
 * // ============================================
 * class ProfiledAction extends Actions
 * {
 *     use AsObservable;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Profile execution:
 * Event::listen('action.started', function ($data) {
 *     \DB::enableQueryLog();
 * });
 *
 * Event::listen('action.completed', function ($data) {
 *     $queries = \DB::getQueryLog();
 *     \Log::info("Action queries", [
 *         'action' => $data['action'],
 *         'queries' => $queries,
 *         'query_count' => count($queries),
 *     ]);
 * });
 *
 * // Usage:
 * ProfiledAction::run();
 * // Logs all database queries executed during action
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - E-commerce Order Processing
 * // ============================================
 * class ProcessEcommerceOrder extends Actions
 * {
 *     use AsObservable;
 *     use AsValidated;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Process order
 *         $order->status = 'processing';
 *         $order->save();
 *
 *         // Charge payment
 *         PaymentService::charge($order);
 *
 *         // Update inventory
 *         InventoryService::reserve($order->items);
 *
 *         // Send notifications
 *         NotificationService::send($order->customer, new OrderConfirmation($order));
 *
 *         $order->status = 'completed';
 *         $order->save();
 *
 *         return $order;
 *     }
 * }
 *
 * // Monitor order processing:
 * Event::listen('action.started', function ($data) {
 *     if ($data['action'] === ProcessEcommerceOrder::class) {
 *         $order = $data['arguments'][0];
 *         \Log::info("Order processing started", ['order_id' => $order->id]);
 *     }
 * });
 *
 * Event::listen('action.completed', function ($data) {
 *     if ($data['action'] === ProcessEcommerceOrder::class) {
 *         $order = $data['result'];
 *         \Log::info("Order processing completed", [
 *             'order_id' => $order->id,
 *             'duration' => $data['duration'],
 *         ]);
 *
 *         // Alert if processing takes too long
 *         if ($data['duration'] > 10.0) {
 *             AlertService::send('Slow order processing', [
 *                 'order_id' => $order->id,
 *                 'duration' => $data['duration'],
 *             ]);
 *         }
 *     }
 * });
 *
 * Event::listen('action.failed', function ($data) {
 *     if ($data['action'] === ProcessEcommerceOrder::class) {
 *         \Log::error("Order processing failed", [
 *             'exception' => $data['exception']->getMessage(),
 *         ]);
 *
 *         // Notify operations team
 *         OperationsTeam::notify('Order processing failure', $data);
 *     }
 * });
 *
 * // Usage:
 * ProcessEcommerceOrder::run($order);
 * // Full observability of order processing lifecycle
 *
 * @see ObservableDecorator
 * @see ObservableDesignPattern
 * @see Event
 */
trait AsObservable
{
    /**
     * Check if events should be fired.
     * Override this method to customize event firing behavior.
     */
    protected function shouldFireEvents(): bool
    {
        if (property_exists($this, 'shouldFireEvents')) {
            return (bool) $this->shouldFireEvents;
        }

        return config('actions.observable.enabled', true);
    }

    /**
     * Register a callback to observe all action events.
     * Convenience method for listening to all action.* events.
     *
     * Note: When using AsAction (which includes both AsObservable and AsObserver),
     * this method is aliased to `observeEvents()` to avoid conflict with
     * AsObserver::observe(). Use `observeEvents()` when using AsAction:
     *
     * ```php
     * class MyAction extends Actions
     * {
     *     use AsAction; // Includes both AsObservable and AsObserver
     * }
     *
     * // Use observeEvents() for observing action events:
     * MyAction::observeEvents(function ($event, $data) {
     *     \Log::info("Action event: {$event}");
     * });
     *
     * // Use observe() for registering as model observer:
     * MyAction::observe(User::class);
     * ```
     *
     * When using AsObservable directly (without AsAction), you can use `observe()`:
     * ```php
     * class MyAction extends Actions
     * {
     *     use AsObservable; // Only AsObservable, no conflict
     * }
     *
     * // Use observe() directly:
     * MyAction::observe(function ($event, $data) {
     *     \Log::info("Action event: {$event}");
     * });
     * ```
     *
     * @param  callable  $callback  Callback to execute for each event
     */
    public static function observe(callable $callback): void
    {
        Event::listen('action.*', function ($event, $data) use ($callback) {
            if (str_starts_with($event, 'action.')) {
                $callback($event, $data);
            }
        });
    }
}
