<?php

namespace App\Actions\Concerns;

use App\Actions\Decorators\MetricsDecorator;
use App\Actions\DesignPatterns\MetricsDesignPattern;
use Illuminate\Support\Facades\Cache;

/**
 * Automatically tracks performance metrics for action execution.
 *
 * Provides automatic metrics tracking capabilities for actions, tracking
 * execution duration, memory usage, success/failure counts, and call frequency.
 * Metrics are stored in cache and can be retrieved via static methods.
 *
 * How it works:
 * - MetricsDesignPattern recognizes actions using AsMetrics
 * - ActionManager wraps the action with MetricsDecorator
 * - When handle() is called, the decorator:
 *    - Records start time and memory
 *    - Executes the action
 *    - Records success metrics (duration, memory)
 *    - On exception, records failure metrics
 *    - Stores metrics in cache
 *    - Returns the result (or re-throws exception)
 *
 * Benefits:
 * - Automatic performance tracking
 * - Execution duration monitoring
 * - Memory usage tracking
 * - Success/failure rate tracking
 * - Min/max duration tracking
 * - Easy metrics retrieval
 * - Configurable TTL
 * - No code changes needed in action's handle() method
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * MetricsDecorator, which automatically wraps actions and tracks metrics.
 * This follows the same pattern as AsOAuth, AsPermission, and other
 * decorator-based concerns.
 *
 * Metrics Tracked:
 * - `calls`: Total number of executions
 * - `successes`: Number of successful executions
 * - `failures`: Number of failed executions
 * - `success_rate`: Percentage of successful executions (0-1)
 * - `avg_duration_ms`: Average execution duration in milliseconds
 * - `min_duration_ms`: Minimum execution duration in milliseconds
 * - `max_duration_ms`: Maximum execution duration in milliseconds
 * - `avg_memory_mb`: Average memory usage in megabytes
 *
 * Configuration:
 * - Set `getMetricsTtl()` method to customize cache TTL (default: 86400 seconds / 24 hours)
 * - Set `metricsTtl` property to customize cache TTL
 *
 * @example
 * // ============================================
 * // Example 1: Basic Metrics Tracking
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(User $user): array
 *     {
 *         // Expensive operation
 *         sleep(1);
 *         return ['data' => 'report data'];
 *     }
 * }
 *
 * // Usage:
 * GenerateReport::run($user);
 * // Automatically tracks execution metrics
 *
 * // Get metrics:
 * $metrics = GenerateReport::getMetrics();
 * // Returns: [
 * //   'calls' => 1,
 * //   'successes' => 1,
 * //   'failures' => 0,
 * //   'success_rate' => 1.0,
 * //   'avg_duration_ms' => 1000.5,
 * //   'min_duration_ms' => 1000.5,
 * //   'max_duration_ms' => 1000.5,
 * //   'avg_memory_mb' => 2.5
 * // ]
 * @example
 * // ============================================
 * // Example 2: Custom Metrics TTL
 * // ============================================
 * class ShortLivedMetrics extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 *
 *     public function getMetricsTtl(): int
 *     {
 *         return 3600; // 1 hour instead of 24 hours
 *     }
 * }
 *
 * // Usage:
 * ShortLivedMetrics::run();
 * // Metrics expire after 1 hour
 * @example
 * // ============================================
 * // Example 3: Using Properties for Configuration
 * // ============================================
 * class ConfigurableMetrics extends Actions
 * {
 *     use AsMetrics;
 *
 *     // Configure via properties
 *     public int $metricsTtl = 7200; // 2 hours
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Usage:
 * $action = ConfigurableMetrics::make();
 * $action->metricsTtl = 1800; // 30 minutes
 * $action->handle();
 * @example
 * // ============================================
 * // Example 4: Monitoring Performance Over Time
 * // ============================================
 * class ExpensiveOperation extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(array $data): array
 *     {
 *         // Expensive operation
 *         return processData($data);
 *     }
 * }
 *
 * // Monitor performance:
 * ExpensiveOperation::run($data);
 *
 * // Check metrics periodically
 * $metrics = ExpensiveOperation::getMetrics();
 * if ($metrics['avg_duration_ms'] > 5000) {
 *     \Log::warning("Slow operation detected", $metrics);
 * }
 *
 * // Alert if success rate drops
 * if ($metrics['success_rate'] < 0.95) {
 *     AlertService::send('Low success rate', $metrics);
 * }
 * @example
 * // ============================================
 * // Example 5: Tracking API Endpoint Performance
 * // ============================================
 * class ApiEndpoint extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(Request $request): array
 *     {
 *         // API logic
 *         return ['data' => 'response'];
 *     }
 * }
 *
 * // In controller:
 * public function index()
 * {
 *     $data = ApiEndpoint::run(request());
 *
 *     // Include metrics in response (optional)
 *     if (request()->has('include_metrics')) {
 *         $data['_metrics'] = ApiEndpoint::getMetrics();
 *     }
 *
 *     return response()->json($data);
 * }
 * @example
 * // ============================================
 * // Example 6: Performance Dashboard
 * // ============================================
 * class DashboardMetrics extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(): array
 *     {
 *         return [
 *             'report_generation' => GenerateReport::getMetrics(),
 *             'data_processing' => ProcessData::getMetrics(),
 *             'api_calls' => ApiCall::getMetrics(),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * $dashboard = DashboardMetrics::run();
 * // Returns metrics for all tracked actions
 * @example
 * // ============================================
 * // Example 7: Resetting Metrics
 * // ============================================
 * class ResetableMetrics extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Usage:
 * ResetableMetrics::run();
 *
 * // Reset metrics (e.g., after deployment)
 * ResetableMetrics::resetMetrics();
 *
 * // Metrics are now cleared
 * $metrics = ResetableMetrics::getMetrics();
 * // Returns: ['calls' => 0, 'successes' => 0, ...]
 * @example
 * // ============================================
 * // Example 8: Tracking Memory-Intensive Operations
 * // ============================================
 * class MemoryIntensiveOperation extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(array $largeDataset): array
 *     {
 *         // Memory-intensive operation
 *         return processLargeDataset($largeDataset);
 *     }
 * }
 *
 * // Monitor memory usage:
 * MemoryIntensiveOperation::run($largeDataset);
 *
 * $metrics = MemoryIntensiveOperation::getMetrics();
 * if ($metrics['avg_memory_mb'] > 100) {
 *     \Log::warning("High memory usage", $metrics);
 *     // Consider optimizing or splitting operation
 * }
 * @example
 * // ============================================
 * // Example 9: Comparing Action Performance
 * // ============================================
 * class ComparePerformance extends Actions
 * {
 *     public function handle(): array
 *     {
 *         return [
 *             'old_implementation' => OldAction::getMetrics(),
 *             'new_implementation' => NewAction::getMetrics(),
 *             'improvement' => $this->calculateImprovement(),
 *         ];
 *     }
 *
 *     protected function calculateImprovement(): float
 *     {
 *         $old = OldAction::getMetrics();
 *         $new = NewAction::getMetrics();
 *
 *         if ($old['avg_duration_ms'] === 0) {
 *             return 0;
 *         }
 *
 *         return (($old['avg_duration_ms'] - $new['avg_duration_ms']) / $old['avg_duration_ms']) * 100;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: Alerting on Performance Degradation
 * // ============================================
 * class MonitoredAction extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // In a scheduled task or event listener:
 * $metrics = MonitoredAction::getMetrics();
 *
 * // Alert if average duration increases significantly
 * if ($metrics['avg_duration_ms'] > $metrics['max_duration_ms'] * 0.8) {
 *     AlertService::send('Performance degradation detected', $metrics);
 * }
 *
 * // Alert if failure rate is high
 * if ($metrics['success_rate'] < 0.90) {
 *     AlertService::send('High failure rate', $metrics);
 * }
 * @example
 * // ============================================
 * // Example 11: Combining with Other Decorators
 * // ============================================
 * class ComprehensiveAction extends Actions
 * {
 *     use AsMetrics;
 *     use AsObservable;
 *     use AsRetry;
 *     use AsTimeout;
 *
 *     public function handle(): void
 *     {
 *         // Operation with metrics, observability, retry, and timeout
 *     }
 * }
 *
 * // Usage:
 * ComprehensiveAction::run();
 * // Combines metrics tracking with other decorators
 * // Metrics track each execution attempt (including retries)
 * @example
 * // ============================================
 * // Example 12: Metrics in API Response
 * // ============================================
 * class ApiAction extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'response'];
 *     }
 * }
 *
 * // In controller (for admin endpoints):
 * public function index()
 * {
 *     $data = ApiAction::run();
 *     $metrics = ApiAction::getMetrics();
 *
 *     return response()->json([
 *         'data' => $data,
 *         'metrics' => $metrics, // Include metrics for monitoring
 *     ]);
 * }
 * @example
 * // ============================================
 * // Example 13: Tracking Success Rate Trends
 * // ============================================
 * class TrackedAction extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Operation
 *     }
 * }
 *
 * // Periodically check success rate:
 * $metrics = TrackedAction::getMetrics();
 *
 * // Store historical data
 * MetricsHistory::create([
 *     'action' => TrackedAction::class,
 *     'success_rate' => $metrics['success_rate'],
 *     'avg_duration_ms' => $metrics['avg_duration_ms'],
 *     'timestamp' => now(),
 * ]);
 *
 * // Analyze trends over time
 * $trend = MetricsHistory::where('action', TrackedAction::class)
 *     ->orderBy('timestamp', 'desc')
 *     ->take(10)
 *     ->pluck('success_rate')
 *     ->avg();
 * @example
 * // ============================================
 * // Example 14: Performance Budget Monitoring
 * // ============================================
 * class BudgetedAction extends Actions
 * {
 *     use AsMetrics;
 *
 *     public function handle(): void
 *     {
 *         // Operation with performance budget
 *     }
 * }
 *
 * // Check if action exceeds performance budget:
 * $metrics = BudgetedAction::getMetrics();
 *
 * $budget = [
 *     'max_avg_duration_ms' => 1000,
 *     'min_success_rate' => 0.95,
 *     'max_memory_mb' => 50,
 * ];
 *
 * if ($metrics['avg_duration_ms'] > $budget['max_avg_duration_ms']) {
 *     \Log::warning("Exceeds duration budget", $metrics);
 * }
 *
 * if ($metrics['success_rate'] < $budget['min_success_rate']) {
 *     \Log::warning("Below success rate budget", $metrics);
 * }
 *
 * if ($metrics['avg_memory_mb'] > $budget['max_memory_mb']) {
 *     \Log::warning("Exceeds memory budget", $metrics);
 * }
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - E-commerce Order Processing
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsMetrics;
 *     use AsValidated;
 *     use AsTransaction;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Process payment
 *         PaymentService::charge($order);
 *
 *         // Update inventory
 *         InventoryService::reserve($order->items);
 *
 *         // Send notifications
 *         NotificationService::send($order->customer, new OrderConfirmation($order));
 *
 *         $order->status = 'processed';
 *         $order->save();
 *
 *         return $order;
 *     }
 * }
 *
 * // Monitor order processing performance:
 * ProcessOrder::run($order);
 *
 * // Check metrics:
 * $metrics = ProcessOrder::getMetrics();
 *
 * // Alert operations team if performance degrades
 * if ($metrics['avg_duration_ms'] > 5000) {
 *     OperationsTeam::alert('Slow order processing', $metrics);
 * }
 *
 * // Track success rate
 * if ($metrics['success_rate'] < 0.99) {
 *     \Log::error("Order processing failures", $metrics);
 *     // Investigate failures
 * }
 *
 * // Weekly performance report
 * $weeklyReport = [
 *     'total_orders' => $metrics['calls'],
 *     'success_rate' => $metrics['success_rate'],
 *     'avg_processing_time' => $metrics['avg_duration_ms'],
 *     'peak_processing_time' => $metrics['max_duration_ms'],
 * ];
 *
 * @see MetricsDecorator
 * @see MetricsDesignPattern
 * @see Cache
 */
trait AsMetrics
{
    /**
     * Get performance metrics for this action.
     */
    public static function getMetrics(): array
    {
        $instance = static::make();
        $key = $instance->getMetricsKey();
        $metrics = Cache::get($key, [
            'calls' => 0,
            'successes' => 0,
            'failures' => 0,
            'total_duration' => 0,
            'total_memory' => 0,
            'min_duration' => 0,
            'max_duration' => 0,
        ]);

        if ($metrics['calls'] === 0) {
            return $metrics;
        }

        return [
            'calls' => $metrics['calls'],
            'successes' => $metrics['successes'],
            'failures' => $metrics['failures'],
            'success_rate' => round($metrics['successes'] / $metrics['calls'], 4),
            'avg_duration_ms' => round(($metrics['total_duration'] / $metrics['calls']) * 1000, 2),
            'min_duration_ms' => round($metrics['min_duration'] * 1000, 2),
            'max_duration_ms' => round($metrics['max_duration'] * 1000, 2),
            'avg_memory_mb' => round(($metrics['total_memory'] / $metrics['calls']) / 1024 / 1024, 2),
        ];
    }

    /**
     * Reset all metrics for this action.
     */
    public static function resetMetrics(): void
    {
        $instance = static::make();
        Cache::forget($instance->getMetricsKey());
    }

    /**
     * Get the cache key for metrics storage.
     * Override this method to customize the cache key.
     */
    protected function getMetricsKey(): string
    {
        return 'metrics:action:'.get_class($this);
    }

    /**
     * Get the TTL for metrics cache.
     * Override this method to customize cache TTL.
     */
    protected function getMetricsTtl(): int
    {
        if (property_exists($this, 'metricsTtl')) {
            return (int) $this->metricsTtl;
        }

        return 86400; // 24 hours
    }
}
