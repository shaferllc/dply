<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\CostTrackingDecorator;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks resource costs (API calls, database queries, etc.) for actions.
 *
 * This trait is a marker that enables automatic cost tracking via CostTrackingDecorator.
 * When an action uses AsCostTracked, CostTrackingDesignPattern recognizes it and
 * ActionManager wraps the action with CostTrackingDecorator.
 *
 * How it works:
 * 1. Action uses AsCostTracked trait (marker)
 * 2. CostTrackingDesignPattern recognizes the trait
 * 3. ActionManager wraps action with CostTrackingDecorator
 * 4. When handle() is called, the decorator:
 *    - Tracks costs during execution
 *    - Enforces cost limits
 *    - Records costs to cache for reporting
 *
 * Features:
 * - Automatic cost tracking during execution
 * - Configurable cost limits per metric
 * - Period-based cost aggregation (daily, weekly, etc.)
 * - Cost retrieval for reporting
 * - Exception on limit exceeded
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Prevents cost overruns
 * - Provides cost visibility
 * - Enables cost-based rate limiting
 * - Composable with other decorators
 * - No trait method conflicts
 *
 * Use Cases:
 * - API call tracking
 * - Database query cost tracking
 * - External service usage tracking
 * - Resource consumption monitoring
 * - Billing and usage reporting
 * - Cost-based rate limiting
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * CostTrackingDecorator, which automatically wraps actions and adds cost tracking.
 * This follows the same pattern as AsDebounced, AsLock, AsLogger, and other
 * decorator-based concerns.
 *
 * IMPORTANT: Unlike AsDebounced (which is a pure marker trait), AsCostTracked
 * includes delegation methods (incrementCost, getCost, etc.) because actions
 * need to call these methods during execution to track costs. The decorator
 * injects itself into the action, and these methods delegate to the decorator.
 * This is necessary because actions call $this->incrementCost() within their
 * handle() methods, so the methods must exist on the action instance.
 *
 * Configuration:
 * - Set `getCostLimit(string $metric)` method to customize limits per metric
 * - Set `getCostTrackingKey()` method to customize cache key prefix
 * - Set `getCostTrackingPeriod()` method to customize period format
 * - Set `getCostTrackingTtl()` method to customize cache TTL
 * - Set config('actions.costs.limits.{metric}') for global limits
 *
 * @example
 * // ============================================
 * // Example 1: Basic API Cost Tracking
 * // ============================================
 * class CallExternalAPI extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         $this->incrementCost('api_calls', 1);
 *         $this->incrementCost('api_cost', 0.001); // $0.001 per call
 *
 *         return Http::get($endpoint)->json();
 *     }
 *
 *     // Optional: customize cost limits
 *     protected function getCostLimit(string $metric): ?float
 *     {
 *         return match($metric) {
 *             'api_calls' => 1000,
 *             'api_cost' => 10.0,
 *             default => null,
 *         };
 *     }
 * }
 *
 * // Usage
 * CallExternalAPI::run('https://api.example.com/data');
 *
 * // Check costs
 * $costs = CallExternalAPI::getCosts('daily');
 * // Returns: ['api_calls' => 1, 'api_cost' => 0.001]
 * @example
 * // ============================================
 * // Example 2: Database Query Cost Tracking
 * // ============================================
 * class ProcessLargeDataset extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(array $data): void
 *     {
 *         foreach ($data as $item) {
 *             // Track database operations
 *             $this->incrementCost('db_queries', 1);
 *             $this->incrementCost('db_cost', 0.0001); // $0.0001 per query
 *
 *             Model::create($item);
 *         }
 *     }
 *
 *     protected function getCostLimit(string $metric): ?float
 *     {
 *         return match($metric) {
 *             'db_queries' => 10000, // Max 10k queries per day
 *             'db_cost' => 1.0,      // Max $1 per day
 *             default => null,
 *         };
 *     }
 * }
 * @example
 * // ============================================
 * // Example 3: Multiple Cost Metrics
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(User $user): Report
 *     {
 *         // Track multiple cost types
 *         $this->incrementCost('cpu_time', 0.5);      // 0.5 CPU seconds
 *         $this->incrementCost('memory_usage', 128);  // 128 MB
 *         $this->incrementCost('api_calls', 3);       // 3 external API calls
 *         $this->incrementCost('total_cost', 0.05);   // $0.05 total
 *
 *         // Generate report...
 *         return new Report($user);
 *     }
 *
 *     protected function getCostLimit(string $metric): ?float
 *     {
 *         return match($metric) {
 *             'cpu_time' => 3600,      // 1 hour max
 *             'memory_usage' => 1024,  // 1 GB max
 *             'api_calls' => 100,      // 100 calls max
 *             'total_cost' => 10.0,     // $10 max
 *             default => null,
 *         };
 *     }
 * }
 *
 * // Check all costs
 * $costs = GenerateReport::getCosts('daily');
 * // Returns: ['cpu_time' => 0.5, 'memory_usage' => 128, 'api_calls' => 3, 'total_cost' => 0.05]
 * @example
 * // ============================================
 * // Example 4: Conditional Cost Tracking
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         $channel = $this->determineChannel($user);
 *
 *         // Track costs based on channel
 *         match($channel) {
 *             'email' => $this->incrementCost('email_sent', 1),
 *             'sms' => $this->incrementCost('sms_sent', 1),
 *             'push' => $this->incrementCost('push_sent', 1),
 *             default => null,
 *         };
 *
 *         // Track monetary cost
 *         $cost = match($channel) {
 *             'email' => 0.001,
 *             'sms' => 0.01,
 *             'push' => 0.0001,
 *             default => 0,
 *         };
 *         $this->incrementCost('notification_cost', $cost);
 *
 *         // Send notification...
 *     }
 *
 *     protected function getCostLimit(string $metric): ?float
 *     {
 *         return match($metric) {
 *             'email_sent' => 10000,
 *             'sms_sent' => 1000,
 *             'push_sent' => 50000,
 *             'notification_cost' => 50.0,
 *             default => null,
 *         };
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Cost Tracking with Custom Period
 * // ============================================
 * class ProcessPayments extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(Payment $payment): void
 *     {
 *         $this->incrementCost('payments_processed', 1);
 *         $this->incrementCost('processing_fee', $payment->fee);
 *
 *         // Process payment...
 *     }
 *
 *     // Override to use weekly period instead of daily
 *     protected function getCostTrackingPeriod(): string
 *     {
 *         return now()->format('Y-W'); // Year-Week format
 *     }
 *
 *     protected function getCostLimit(string $metric): ?float
 *     {
 *         return match($metric) {
 *             'payments_processed' => 100000,
 *             'processing_fee' => 1000.0,
 *             default => null,
 *         };
 *     }
 * }
 *
 * // Get weekly costs
 * $weeklyCosts = ProcessPayments::getCosts('2024-15'); // Week 15 of 2024
 * @example
 * // ============================================
 * // Example 6: Cost Tracking with Limits from Config
 * // ============================================
 * class AnalyzeData extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(array $data): Analysis
 *     {
 *         $this->incrementCost('data_points', count($data));
 *         $this->incrementCost('analysis_cost', count($data) * 0.0001);
 *
 *         // Analyze data...
 *         return new Analysis($data);
 *     }
 *
 *     // Limits come from config('actions.costs.limits.data_points')
 *     // No need to override getCostLimit() if using config
 * }
 *
 * // In config/actions.php:
 * // 'costs' => [
 * //     'limits' => [
 * //         'data_points' => 1000000,
 * //         'analysis_cost' => 100.0,
 * //     ],
 * // ],
 * @example
 * // ============================================
 * // Example 7: Cost Tracking in Loops
 * // ============================================
 * class BatchProcessItems extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(array $items): void
 *     {
 *         foreach ($items as $item) {
 *             // Track cost per item
 *             $this->incrementCost('items_processed', 1);
 *             $this->incrementCost('processing_cost', 0.01);
 *
 *             // If limit exceeded, exception is thrown automatically
 *             $this->processItem($item);
 *         }
 *     }
 *
 *     protected function getCostLimit(string $metric): ?float
 *     {
 *         return match($metric) {
 *             'items_processed' => 1000,
 *             'processing_cost' => 10.0,
 *             default => null,
 *         };
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Getting Current Execution Costs
 * // ============================================
 * class ComplexOperation extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(): void
 *     {
 *         $this->incrementCost('step1', 1);
 *         $this->doStep1();
 *
 *         $this->incrementCost('step2', 2);
 *         $this->doStep2();
 *
 *         // Check current costs during execution
 *         $currentCosts = $this->getAllCosts();
 *         // Returns: ['step1' => 1, 'step2' => 2]
 *
 *         $step1Cost = $this->getCost('step1');
 *         // Returns: 1
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Cost Tracking with Custom TTL
 * // ============================================
 * class LongTermTracking extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(): void
 *     {
 *         $this->incrementCost('operations', 1);
 *     }
 *
 *     // Store costs for 90 days instead of default 30
 *     protected function getCostTrackingTtl(): int
 *     {
 *         return 86400 * 90; // 90 days
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: Cost Tracking with Custom Key
 * // ============================================
 * class UserSpecificOperation extends Actions
 * {
 *     use AsCostTracked;
 *
 *     public function handle(User $user): void
 *     {
 *         $this->incrementCost('user_operations', 1);
 *     }
 *
 *     // Track costs per user instead of per action class
 *     protected function getCostTrackingKey(): string
 *     {
 *         $user = auth()->user();
 *         return 'costs:'.get_class($this).':user:'.$user->id;
 *     }
 * }
 */
trait AsCostTracked
{
    /**
     * Reference to the cost tracking decorator (injected by decorator).
     */
    protected ?CostTrackingDecorator $_costTrackingDecorator = null;

    /**
     * Set the cost tracking decorator reference.
     *
     * Called by CostTrackingDecorator to inject itself.
     */
    public function setCostTrackingDecorator(CostTrackingDecorator $decorator): void
    {
        $this->_costTrackingDecorator = $decorator;
    }

    /**
     * Get the cost tracking decorator.
     */
    protected function getCostTrackingDecorator(): ?CostTrackingDecorator
    {
        return $this->_costTrackingDecorator;
    }

    /**
     * Increment cost for a specific metric.
     *
     * @param  string  $metric  The cost metric name
     * @param  float  $amount  The amount to increment
     *
     * @throws \RuntimeException If cost limit is exceeded
     */
    public function incrementCost(string $metric, float $amount): void
    {
        $decorator = $this->getCostTrackingDecorator();
        if ($decorator) {
            $decorator->incrementCost($metric, $amount);
        }
    }

    /**
     * Get the current cost for a specific metric during this execution.
     *
     * @param  string  $metric  The cost metric name
     * @return float The current cost amount for this metric
     */
    public function getCost(string $metric): float
    {
        $decorator = $this->getCostTrackingDecorator();
        if ($decorator) {
            return $decorator->getCost($metric);
        }

        return 0;
    }

    /**
     * Get all current costs during this execution.
     *
     * @return array<string, float> All cost metrics and their amounts
     */
    public function getAllCosts(): array
    {
        $decorator = $this->getCostTrackingDecorator();
        if ($decorator) {
            return $decorator->getAllCosts();
        }

        return [];
    }

    /**
     * Get aggregated costs for a specific period.
     *
     * @param  string  $period  The period identifier (e.g., '2024-01-15' for daily, '2024-15' for weekly)
     *                          If 'daily' is passed, uses today's date format
     * @return array<string, float> All cost metrics and their aggregated amounts for the period
     *
     * @example
     * // Get today's costs
     * $dailyCosts = MyAction::getCosts('daily');
     * // or
     * $dailyCosts = MyAction::getCosts(now()->format('Y-m-d'));
     *
     * // Get costs for a specific date
     * $specificCosts = MyAction::getCosts('2024-01-15');
     */
    public static function getCosts(string $period = 'daily'): array
    {
        $actionClass = static::class;
        $defaultPeriod = $period === 'daily' ? now()->format('Y-m-d') : $period;

        // Use the decorator's static method to get costs
        return CostTrackingDecorator::getCostsForAction($actionClass, $defaultPeriod);
    }
}
