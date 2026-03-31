<?php

namespace App\Actions\Decorators;

use App\Actions\ActionEvents;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Metrics Decorator
 *
 * Automatically tracks performance metrics for action execution.
 * This decorator intercepts handle() calls and tracks execution duration,
 * memory usage, success/failure counts, and call frequency.
 *
 * Features:
 * - Automatic execution duration tracking
 * - Memory usage tracking
 * - Success/failure count tracking
 * - Call frequency tracking
 * - Min/max duration tracking
 * - Metrics stored in cache
 * - Configurable TTL
 * - Static methods to retrieve metrics
 *
 * How it works:
 * 1. When an action uses AsMetrics, MetricsDesignPattern recognizes it
 * 2. ActionManager wraps the action with MetricsDecorator
 * 3. When handle() is called, the decorator:
 *    - Records start time and memory
 *    - Executes the action
 *    - Records success metrics (duration, memory)
 *    - On exception, records failure metrics
 *    - Stores metrics in cache
 *    - Returns the result (or re-throws exception)
 *
 * Metrics Tracked:
 * - `calls`: Total number of executions
 * - `successes`: Number of successful executions
 * - `failures`: Number of failed executions
 * - `success_rate`: Percentage of successful executions
 * - `avg_duration_ms`: Average execution duration in milliseconds
 * - `min_duration_ms`: Minimum execution duration in milliseconds
 * - `max_duration_ms`: Maximum execution duration in milliseconds
 * - `avg_memory_mb`: Average memory usage in megabytes
 */
class MetricsDecorator
{
    use DecorateActions;

    protected ?float $metricsStartTime = null;

    protected ?int $metricsStartMemory = null;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with metrics tracking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Throwable
     */
    public function handle(...$arguments)
    {
        ActionEvents::beforeExecution(get_class($this->action), $arguments);

        $this->metricsStartTime = microtime(true);
        $this->metricsStartMemory = memory_get_usage(true);

        try {
            $result = $this->callMethod('handle', $arguments);
            $this->recordSuccess($arguments);
            ActionEvents::afterExecution(get_class($this->action), $result, $arguments);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($arguments);
            ActionEvents::onFailure(get_class($this->action), $e, $arguments);

            throw $e;
        }
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Record successful execution metrics.
     */
    protected function recordSuccess(array $arguments): void
    {
        $duration = $this->getExecutionDuration();
        $memory = $this->getMemoryUsage();
        $key = $this->getMetricsKey();

        $metrics = Cache::get($key, [
            'calls' => 0,
            'successes' => 0,
            'failures' => 0,
            'total_duration' => 0,
            'total_memory' => 0,
            'min_duration' => PHP_FLOAT_MAX,
            'max_duration' => 0,
        ]);

        $metrics['calls']++;
        $metrics['successes']++;
        $metrics['total_duration'] += $duration;
        $metrics['total_memory'] += $memory;
        $metrics['min_duration'] = min($metrics['min_duration'], $duration);
        $metrics['max_duration'] = max($metrics['max_duration'], $duration);

        Cache::put($key, $metrics, $this->getMetricsTtl());
    }

    /**
     * Record failed execution metrics.
     */
    protected function recordFailure(array $arguments): void
    {
        $key = $this->getMetricsKey();

        $metrics = Cache::get($key, [
            'calls' => 0,
            'successes' => 0,
            'failures' => 0,
            'total_duration' => 0,
            'total_memory' => 0,
            'min_duration' => PHP_FLOAT_MAX,
            'max_duration' => 0,
        ]);

        $metrics['calls']++;
        $metrics['failures']++;

        Cache::put($key, $metrics, $this->getMetricsTtl());
    }

    /**
     * Get the cache key for metrics storage.
     */
    protected function getMetricsKey(): string
    {
        return 'metrics:action:'.get_class($this->action);
    }

    /**
     * Get the TTL for metrics cache.
     */
    protected function getMetricsTtl(): int
    {
        return $this->fromActionMethodOrProperty('getMetricsTtl', 'metricsTtl', 86400); // 24 hours
    }

    /**
     * Get execution duration in seconds.
     */
    protected function getExecutionDuration(): float
    {
        return $this->metricsStartTime ? microtime(true) - $this->metricsStartTime : 0;
    }

    /**
     * Get memory usage in bytes.
     */
    protected function getMemoryUsage(): int
    {
        return $this->metricsStartMemory ? memory_get_usage(true) - $this->metricsStartMemory : 0;
    }
}
