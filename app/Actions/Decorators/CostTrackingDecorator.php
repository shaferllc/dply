<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Decorator that tracks resource costs for actions.
 *
 * This decorator automatically tracks costs during action execution and enforces
 * cost limits. Costs are stored in cache with configurable periods.
 */
class CostTrackingDecorator
{
    use DecorateActions;

    protected array $costs = [];

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setCostTrackingDecorator')) {
            $action->setCostTrackingDecorator($this);
        } elseif (property_exists($action, '_costTrackingDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_costTrackingDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        $this->costs = [];

        try {
            $result = $this->callMethod('handle', $arguments);
            $this->recordCosts();

            return $result;
        } catch (\Throwable $e) {
            $this->recordCosts();

            throw $e;
        }
    }

    /**
     * Increment cost for a specific metric.
     *
     * This method can be called from the action via the AsCostTracked trait.
     *
     * @param  string  $metric  The cost metric name
     * @param  float  $amount  The amount to increment
     *
     * @throws \RuntimeException If cost limit is exceeded
     */
    public function incrementCost(string $metric, float $amount): void
    {
        if (! isset($this->costs[$metric])) {
            $this->costs[$metric] = 0;
        }

        $this->costs[$metric] += $amount;

        $limit = $this->getCostLimit($metric);
        if ($limit !== null && $this->costs[$metric] > $limit) {
            throw new \RuntimeException("Cost limit exceeded for metric '{$metric}': {$this->costs[$metric]} > {$limit}");
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
        return $this->costs[$metric] ?? 0;
    }

    /**
     * Get all current costs during this execution.
     *
     * @return array<string, float> All cost metrics and their amounts
     */
    public function getAllCosts(): array
    {
        return $this->costs;
    }

    protected function recordCosts(): void
    {
        if (empty($this->costs)) {
            return;
        }

        $key = $this->getCostTrackingKey();
        $period = $this->getCostTrackingPeriod();

        foreach ($this->costs as $metric => $amount) {
            $this->incrementCostMetric($key, $metric, $amount, $period);
        }
    }

    protected function incrementCostMetric(string $key, string $metric, float $amount, string $period): void
    {
        $cacheKey = "{$key}:{$metric}:{$period}";
        $current = Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + $amount, $this->getCostTrackingTtl());
    }

    protected function getCostLimit(string $metric): ?float
    {
        if ($this->hasMethod('getCostLimit')) {
            return $this->callMethod('getCostLimit', [$metric]);
        }

        return config("actions.costs.limits.{$metric}");
    }

    protected function getCostTrackingKey(): string
    {
        return 'costs:'.get_class($this->action);
    }

    protected function getCostTrackingPeriod(): string
    {
        return now()->format('Y-m-d');
    }

    protected function getCostTrackingTtl(): int
    {
        return 86400 * 30;
    }

    /**
     * Get aggregated costs for a specific period.
     *
     * @param  string  $actionClass  The action class name
     * @param  string  $period  The period identifier (e.g., '2024-01-15' for daily)
     * @return array<string, float> All cost metrics and their aggregated amounts
     */
    public static function getCostsForAction(string $actionClass, string $period = 'daily'): array
    {
        $key = 'costs:'.$actionClass;
        $costs = [];

        // Scan cache for all metrics for this action and period
        // This is a simplified approach - in production you might want to track
        // which metrics exist in a separate cache key
        $prefix = "{$key}:";
        $suffix = ":{$period}";

        // Try common metric names
        $commonMetrics = [
            'api_calls', 'api_cost', 'db_queries', 'db_cost', 'cpu_time',
            'memory_usage', 'total_cost', 'email_sent', 'sms_sent', 'push_sent',
            'notification_cost', 'payments_processed', 'processing_fee',
            'data_points', 'analysis_cost', 'items_processed', 'processing_cost',
            'user_operations', 'operations',
        ];

        foreach ($commonMetrics as $metric) {
            $cacheKey = "{$prefix}{$metric}{$suffix}";
            $value = Cache::get($cacheKey, 0);
            if ($value > 0) {
                $costs[$metric] = $value;
            }
        }

        return $costs;
    }
}
