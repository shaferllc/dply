<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Collection;

/**
 * Action Performance Benchmarking - Compare action performance.
 *
 * Provides benchmarking capabilities to compare actions and detect regressions.
 *
 * @example
 * // Run benchmark for an action
 * $benchmark = ActionBenchmark::benchmark(ProcessOrder::class, [$order], 10);
 * // Returns: [
 * //     'action' => 'App\Actions\ProcessOrder',
 * //     'iterations' => 10,
 * //     'avg_duration_ms' => 234.5,
 * //     'min_duration_ms' => 120.0,
 * //     'max_duration_ms' => 450.0,
 * //     'memory_avg_mb' => 2.5,
 * // ]
 * @example
 * // Compare two actions
 * $comparison = ActionBenchmark::compare(
 *     ProcessOrderV1::class,
 *     ProcessOrderV2::class,
 *     [$order],
 *     10
 * );
 * @example
 * // Detect performance regression
 * $regression = ActionBenchmark::detectRegression(ProcessOrder::class, 10);
 * @example
 * // Get benchmark history
 * $history = ActionBenchmark::getHistory(ProcessOrder::class);
 */
class ActionBenchmark
{
    /**
     * Run benchmark for an action.
     *
     * @param  string  $actionClass  Action class name
     * @param  array  $arguments  Arguments to pass to action
     * @param  int  $iterations  Number of iterations
     * @return array<string, mixed> Benchmark results
     */
    public static function benchmark(string $actionClass, array $arguments = [], int $iterations = 10): array
    {
        $durations = [];
        $memories = [];

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            try {
                $action = app($actionClass);
                $action->handle(...$arguments);
            } catch (\Throwable $e) {
                // Skip failed iterations
                continue;
            }

            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);

            $durations[] = ($endTime - $startTime) * 1000; // Convert to milliseconds
            $memories[] = ($endMemory - $startMemory) / 1024 / 1024; // Convert to MB
        }

        if (empty($durations)) {
            return [
                'action' => $actionClass,
                'iterations' => 0,
                'avg_duration_ms' => 0,
                'min_duration_ms' => 0,
                'max_duration_ms' => 0,
                'memory_avg_mb' => 0,
            ];
        }

        $result = [
            'action' => $actionClass,
            'iterations' => count($durations),
            'avg_duration_ms' => array_sum($durations) / count($durations),
            'min_duration_ms' => min($durations),
            'max_duration_ms' => max($durations),
            'memory_avg_mb' => array_sum($memories) / count($memories),
            'timestamp' => now()->toIso8601String(),
        ];

        // Store benchmark result
        static::storeBenchmark($actionClass, $result);

        return $result;
    }

    /**
     * Compare two actions.
     *
     * @param  string  $action1  First action class name
     * @param  string  $action2  Second action class name
     * @param  array  $arguments  Arguments to pass to actions
     * @param  int  $iterations  Number of iterations
     * @return array<string, mixed> Comparison results
     */
    public static function compare(string $action1, string $action2, array $arguments = [], int $iterations = 10): array
    {
        $benchmark1 = static::benchmark($action1, $arguments, $iterations);
        $benchmark2 = static::benchmark($action2, $arguments, $iterations);

        $durationDiff = $benchmark2['avg_duration_ms'] - $benchmark1['avg_duration_ms'];
        $durationPercent = $benchmark1['avg_duration_ms'] > 0
            ? ($durationDiff / $benchmark1['avg_duration_ms']) * 100
            : 0;

        return [
            'action1' => $benchmark1,
            'action2' => $benchmark2,
            'duration_diff_ms' => $durationDiff,
            'duration_percent_change' => $durationPercent,
            'faster' => $durationDiff < 0 ? $action2 : $action1,
            'slower' => $durationDiff > 0 ? $action2 : $action1,
        ];
    }

    /**
     * Detect performance regression.
     *
     * @param  string  $actionClass  Action class name
     * @param  int  $iterations  Number of iterations for current benchmark
     * @param  float  $threshold  Performance degradation threshold (percentage)
     * @return array<string, mixed>|null Regression data or null if no regression
     */
    public static function detectRegression(string $actionClass, int $iterations = 10, float $threshold = 20.0): ?array
    {
        $current = static::benchmark($actionClass, [], $iterations);
        $history = static::getHistory($actionClass);

        if ($history->isEmpty()) {
            return null;
        }

        $baseline = $history->first();
        $degradation = (($current['avg_duration_ms'] - $baseline['avg_duration_ms']) / $baseline['avg_duration_ms']) * 100;

        if ($degradation > $threshold) {
            return [
                'action' => $actionClass,
                'baseline' => $baseline,
                'current' => $current,
                'degradation_percent' => $degradation,
                'threshold' => $threshold,
                'regression_detected' => true,
            ];
        }

        return null;
    }

    /**
     * Get benchmark history for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return Collection<array> Benchmark history
     */
    public static function getHistory(string $actionClass): Collection
    {
        $key = "benchmark_history:{$actionClass}";

        return collect(cache()->get($key, []));
    }

    /**
     * Store benchmark result.
     */
    protected static function storeBenchmark(string $actionClass, array $result): void
    {
        $key = "benchmark_history:{$actionClass}";
        $history = collect(cache()->get($key, []));
        $history->prepend($result);
        $history = $history->take(50); // Keep last 50 benchmarks
        cache()->put($key, $history->toArray(), 86400 * 30); // Store for 30 days
    }
}
