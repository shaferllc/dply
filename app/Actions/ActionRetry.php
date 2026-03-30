<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\AsRetry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Action Retry Dashboard - Monitor retry attempts and failures.
 *
 * Provides dashboard and management capabilities for action retries.
 *
 * @example
 * // Get retry statistics for an action
 * $stats = ActionRetry::getStats(ProcessOrder::class);
 * // Returns: [
 * //     'action' => 'App\Actions\ProcessOrder',
 * //     'total_attempts' => 150,
 * //     'successful_attempts' => 145,
 * //     'failed_attempts' => 5,
 * //     'retry_count' => 12,
 * //     'success_rate' => 0.9667,
 * //     'avg_retries' => 0.08,
 * // ]
 * @example
 * // Get dashboard for all retry-enabled actions
 * $dashboard = ActionRetry::dashboard();
 * // Returns: [
 * //     'total_retry_actions' => 10,
 * //     'high_retry_rate' => 2,
 * //     'actions' => [...],
 * // ]
 * @example
 * // Get actions with high retry rates
 * $highRetry = ActionRetry::getHighRetryRate(10);
 * @example
 * // Clear retry statistics
 * ActionRetry::clearStats(ProcessOrder::class);
 * ActionRetry::clearAllStats();
 */
class ActionRetry
{
    /**
     * Get retry statistics for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Retry statistics
     */
    public static function getStats(string $actionClass): array
    {
        $key = static::getRetryStatsKey($actionClass);
        $stats = Cache::get($key, [
            'total_attempts' => 0,
            'successful_attempts' => 0,
            'failed_attempts' => 0,
            'retry_count' => 0,
            'total_retries' => 0,
        ]);

        $totalAttempts = $stats['total_attempts'];
        $successfulAttempts = $stats['successful_attempts'];
        $retryCount = $stats['retry_count'];

        return [
            'action' => $actionClass,
            'total_attempts' => $totalAttempts,
            'successful_attempts' => $successfulAttempts,
            'failed_attempts' => $stats['failed_attempts'],
            'retry_count' => $retryCount,
            'success_rate' => $totalAttempts > 0 ? $successfulAttempts / $totalAttempts : 0.0,
            'avg_retries' => $totalAttempts > 0 ? $stats['total_retries'] / $totalAttempts : 0.0,
        ];
    }

    /**
     * Get dashboard data for all retry-enabled actions.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::getByTrait(AsRetry::class);
        $retryStats = collect();
        $highRetryRate = 0;

        foreach ($actions as $actionClass) {
            $stats = static::getStats($actionClass);
            $retryStats->push($stats);

            if ($stats['avg_retries'] > 0.5) {
                $highRetryRate++;
            }
        }

        return [
            'total_retry_actions' => $retryStats->count(),
            'high_retry_rate' => $highRetryRate,
            'actions' => $retryStats->sortByDesc('avg_retries')->values()->toArray(),
        ];
    }

    /**
     * Get actions with high retry rates.
     *
     * @param  int  $limit  Number of actions to return
     * @return Collection<array> Actions with high retry rates
     */
    public static function getHighRetryRate(int $limit = 10): Collection
    {
        $actions = ActionRegistry::getByTrait(AsRetry::class);

        return collect($actions)
            ->map(fn ($action) => static::getStats($action))
            ->filter(fn ($stats) => $stats['avg_retries'] > 0.5)
            ->sortByDesc('avg_retries')
            ->take($limit);
    }

    /**
     * Clear retry statistics for an action.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function clearStats(string $actionClass): void
    {
        $key = static::getRetryStatsKey($actionClass);
        Cache::forget($key);
    }

    /**
     * Clear all retry statistics.
     */
    public static function clearAllStats(): void
    {
        $actions = ActionRegistry::getByTrait(AsRetry::class);

        foreach ($actions as $actionClass) {
            static::clearStats($actionClass);
        }
    }

    /**
     * Record a retry attempt.
     *
     * @param  string  $actionClass  Action class name
     * @param  bool  $success  Whether the attempt was successful
     * @param  int  $retries  Number of retries made
     */
    public static function recordAttempt(string $actionClass, bool $success, int $retries = 0): void
    {
        $key = static::getRetryStatsKey($actionClass);
        $stats = Cache::get($key, [
            'total_attempts' => 0,
            'successful_attempts' => 0,
            'failed_attempts' => 0,
            'retry_count' => 0,
            'total_retries' => 0,
        ]);

        $stats['total_attempts']++;
        $stats['total_retries'] += $retries;

        if ($success) {
            $stats['successful_attempts']++;
        } else {
            $stats['failed_attempts']++;
            if ($retries > 0) {
                $stats['retry_count']++;
            }
        }

        Cache::put($key, $stats, 86400 * 7); // Store for 7 days
    }

    /**
     * Get retry statistics cache key.
     */
    protected static function getRetryStatsKey(string $actionClass): string
    {
        return "retry_stats:{$actionClass}";
    }
}
