<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Enhanced Action Metrics - Dashboard and alerting for action performance.
 *
 * Provides comprehensive metrics tracking, dashboards, and alerting
 * for action performance monitoring.
 *
 * Metrics are automatically tracked by MetricsDecorator when actions
 * use the AsMetrics trait.
 *
 * @example
 * // Get metrics for a specific action
 * $metrics = ActionMetrics::getMetrics(ProcessOrder::class);
 * // Returns: [
 * //     'calls' => 150,
 * //     'successes' => 145,
 * //     'failures' => 5,
 * //     'success_rate' => 0.9667,
 * //     'avg_duration_ms' => 234.5,
 * //     'min_duration_ms' => 120.0,
 * //     'max_duration_ms' => 450.0,
 * //     'avg_memory_mb' => 2.5,
 * // ]
 * @example
 * // Get slowest actions
 * $slowest = ActionMetrics::getSlowestActions(10);
 * foreach ($slowest as $action) {
 *     echo "{$action['action']}: {$action['avg_duration_ms']}ms\n";
 * }
 * @example
 * // Get most called actions
 * $mostCalled = ActionMetrics::getMostCalledActions(10);
 * foreach ($mostCalled as $action) {
 *     echo "{$action['action']}: {$action['calls']} calls\n";
 * }
 * @example
 * // Get actions with highest failure rate
 * $failures = ActionMetrics::getHighestFailureRate(10);
 * foreach ($failures as $action) {
 *     $rate = $action['failure_rate'] * 100;
 *     echo "{$action['action']}: {$rate}% failure rate\n";
 * }
 * @example
 * // Get complete dashboard
 * $dashboard = ActionMetrics::dashboard();
 * // Returns: [
 * //     'summary' => [...],
 * //     'slowest' => [...],
 * //     'most_called' => [...],
 * //     'highest_failure_rate' => [...],
 * // ]
 * @example
 * // Set up alerts for slow actions
 * ActionMetrics::alertOnSlowAction(ProcessOrder::class, 5000, function ($action, $metrics) {
 *     \Log::warning("Action {$action} is slow", [
 *         'avg_duration_ms' => $metrics['avg_duration_ms'],
 *         'calls' => $metrics['calls'],
 *     ]);
 *
 *     // Send notification
 *     \Notification::route('slack', '#alerts')
 *         ->notify(new SlowActionAlert($action, $metrics));
 * });
 *
 * // Check if alert threshold was exceeded
 * $exceeded = ActionMetrics::checkAlert(ProcessOrder::class);
 */
class ActionMetrics
{
    /**
     * Get metrics for a specific action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Metrics data
     */
    public static function getMetrics(string $actionClass): array
    {
        $key = "metrics:action:{$actionClass}";
        $rawMetrics = Cache::get($key, [
            'calls' => 0,
            'successes' => 0,
            'failures' => 0,
            'total_duration' => 0,
            'total_memory' => 0,
            'min_duration' => PHP_FLOAT_MAX,
            'max_duration' => 0,
        ]);

        $calls = $rawMetrics['calls'];
        $successes = $rawMetrics['successes'];
        $failures = $rawMetrics['failures'];

        return [
            'calls' => $calls,
            'successes' => $successes,
            'failures' => $failures,
            'success_rate' => $calls > 0 ? $successes / $calls : 0.0,
            'avg_duration_ms' => $calls > 0 ? ($rawMetrics['total_duration'] / $calls) * 1000 : 0.0,
            'min_duration_ms' => $rawMetrics['min_duration'] !== PHP_FLOAT_MAX ? $rawMetrics['min_duration'] * 1000 : null,
            'max_duration_ms' => $rawMetrics['max_duration'] > 0 ? $rawMetrics['max_duration'] * 1000 : null,
            'avg_memory_mb' => $calls > 0 ? ($rawMetrics['total_memory'] / $calls) / 1024 / 1024 : 0.0,
        ];
    }

    /**
     * Get the slowest actions.
     *
     * @param  int  $limit  Number of actions to return
     * @return Collection<array> Actions sorted by average duration
     */
    public static function getSlowestActions(int $limit = 10): Collection
    {
        $actions = ActionRegistry::all();
        $metrics = collect();

        foreach ($actions as $actionClass) {
            $actionMetrics = static::getMetrics($actionClass);
            if ($actionMetrics['calls'] > 0) {
                $metrics->push([
                    'action' => $actionClass,
                    'avg_duration_ms' => $actionMetrics['avg_duration_ms'],
                    'calls' => $actionMetrics['calls'],
                ]);
            }
        }

        return $metrics->sortByDesc('avg_duration_ms')->take($limit);
    }

    /**
     * Get the most called actions.
     *
     * @param  int  $limit  Number of actions to return
     * @return Collection<array> Actions sorted by call count
     */
    public static function getMostCalledActions(int $limit = 10): Collection
    {
        $actions = ActionRegistry::all();
        $metrics = collect();

        foreach ($actions as $actionClass) {
            $actionMetrics = static::getMetrics($actionClass);
            if ($actionMetrics['calls'] > 0) {
                $metrics->push([
                    'action' => $actionClass,
                    'calls' => $actionMetrics['calls'],
                    'avg_duration_ms' => $actionMetrics['avg_duration_ms'],
                ]);
            }
        }

        return $metrics->sortByDesc('calls')->take($limit);
    }

    /**
     * Get actions with highest failure rate.
     *
     * @param  int  $limit  Number of actions to return
     * @return Collection<array> Actions sorted by failure rate
     */
    public static function getHighestFailureRate(int $limit = 10): Collection
    {
        $actions = ActionRegistry::all();
        $metrics = collect();

        foreach ($actions as $actionClass) {
            $actionMetrics = static::getMetrics($actionClass);
            if ($actionMetrics['calls'] > 0 && $actionMetrics['failures'] > 0) {
                $failureRate = $actionMetrics['failures'] / $actionMetrics['calls'];
                $metrics->push([
                    'action' => $actionClass,
                    'failure_rate' => $failureRate,
                    'failures' => $actionMetrics['failures'],
                    'calls' => $actionMetrics['calls'],
                ]);
            }
        }

        return $metrics->sortByDesc('failure_rate')->take($limit);
    }

    /**
     * Get dashboard data for all actions.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::all();
        $totalCalls = 0;
        $totalSuccesses = 0;
        $totalFailures = 0;
        $totalDuration = 0.0;
        $actionCount = 0;

        foreach ($actions as $actionClass) {
            $metrics = static::getMetrics($actionClass);
            if ($metrics['calls'] > 0) {
                $totalCalls += $metrics['calls'];
                $totalSuccesses += $metrics['successes'];
                $totalFailures += $metrics['failures'];
                $totalDuration += $metrics['avg_duration_ms'] * $metrics['calls'];
                $actionCount++;
            }
        }

        $avgDuration = $totalCalls > 0 ? $totalDuration / $totalCalls : 0.0;
        $successRate = $totalCalls > 0 ? $totalSuccesses / $totalCalls : 0.0;

        return [
            'summary' => [
                'total_actions' => $actions->count(),
                'active_actions' => $actionCount,
                'total_calls' => $totalCalls,
                'total_successes' => $totalSuccesses,
                'total_failures' => $totalFailures,
                'overall_success_rate' => $successRate,
                'avg_duration_ms' => $avgDuration,
            ],
            'slowest' => static::getSlowestActions(10)->toArray(),
            'most_called' => static::getMostCalledActions(10)->toArray(),
            'highest_failure_rate' => static::getHighestFailureRate(10)->toArray(),
        ];
    }

    /**
     * Set up an alert for slow actions.
     *
     * @param  string  $actionClass  Action class name
     * @param  int  $thresholdMs  Threshold in milliseconds
     * @param  callable|null  $callback  Callback to execute when threshold is exceeded
     */
    public static function alertOnSlowAction(string $actionClass, int $thresholdMs, ?callable $callback = null): void
    {
        $key = "action_alert:slow:{$actionClass}";
        Cache::put($key, [
            'threshold_ms' => $thresholdMs,
            'callback' => $callback,
        ], 86400 * 365); // Store for 1 year
    }

    /**
     * Check if an action exceeded its alert threshold.
     *
     * @param  string  $actionClass  Action class name
     * @return bool True if threshold was exceeded
     */
    public static function checkAlert(string $actionClass): bool
    {
        $alertKey = "action_alert:slow:{$actionClass}";
        $alert = Cache::get($alertKey);

        if (! $alert) {
            return false;
        }

        $metrics = static::getMetrics($actionClass);
        $exceeded = $metrics['avg_duration_ms'] > $alert['threshold_ms'];

        if ($exceeded && $alert['callback']) {
            call_user_func($alert['callback'], $actionClass, $metrics);
        }

        return $exceeded;
    }
}
