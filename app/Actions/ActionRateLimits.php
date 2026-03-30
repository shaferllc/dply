<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Cache;

/**
 * Action Rate Limits Dashboard - Visualize and manage rate limits.
 *
 * Provides dashboard and management capabilities for action rate limiting.
 *
 * @example
 * // Get rate limit status for an action
 * $status = ActionRateLimits::getStatus(ProcessOrder::class);
 * // Returns: [
 * //     'action' => 'App\Actions\ProcessOrder',
 * //     'current_requests' => 45,
 * //     'max_requests' => 100,
 * //     'remaining' => 55,
 * //     'reset_at' => '2024-01-15T11:00:00Z',
 * //     'status' => 'available', // or 'limited'
 * // ]
 * @example
 * // Get dashboard for all rate-limited actions
 * $dashboard = ActionRateLimits::dashboard();
 * // Returns: [
 * //     'total_rate_limited' => 10,
 * //     'currently_limited' => 2,
 * //     'actions' => [...],
 * // ]
 * @example
 * // Reset rate limit for specific action
 * ActionRateLimits::reset(ProcessOrder::class);
 * @example
 * // Reset all rate limits
 * ActionRateLimits::resetAll();
 */
class ActionRateLimits
{
    /**
     * Get rate limit status for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Rate limit status
     */
    public static function getStatus(string $actionClass): array
    {
        $key = "rate_limit:{$actionClass}";
        $data = Cache::get($key, []);

        return [
            'action' => $actionClass,
            'current_requests' => $data['current'] ?? 0,
            'max_requests' => $data['max'] ?? null,
            'remaining' => isset($data['max']) ? max(0, $data['max'] - ($data['current'] ?? 0)) : null,
            'reset_at' => $data['reset_at'] ?? null,
            'status' => isset($data['max']) && ($data['current'] ?? 0) >= $data['max'] ? 'limited' : 'available',
        ];
    }

    /**
     * Get dashboard data for all rate-limited actions.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::all();
        $rateLimited = collect();

        foreach ($actions as $actionClass) {
            $status = static::getStatus($actionClass);
            if ($status['max_requests'] !== null) {
                $rateLimited->push($status);
            }
        }

        return [
            'total_rate_limited' => $rateLimited->count(),
            'currently_limited' => $rateLimited->filter(fn ($s) => $s['status'] === 'limited')->count(),
            'actions' => $rateLimited->sortByDesc('current_requests')->values()->toArray(),
        ];
    }

    /**
     * Reset rate limit for an action.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function reset(string $actionClass): void
    {
        $key = "rate_limit:{$actionClass}";
        Cache::forget($key);
    }

    /**
     * Reset rate limits for all actions.
     */
    public static function resetAll(): void
    {
        $actions = ActionRegistry::all();

        foreach ($actions as $actionClass) {
            static::reset($actionClass);
        }
    }
}
