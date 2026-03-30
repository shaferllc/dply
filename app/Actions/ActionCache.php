<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\AsCachedResult;
use Illuminate\Support\Facades\Cache;

/**
 * Action Cache Dashboard - Manage cached actions.
 *
 * Provides dashboard and management capabilities for action caching.
 *
 * Works with actions that use the AsCachedResult trait.
 *
 * @example
 * // Get cache status for an action
 * $status = ActionCache::getStatus(ProcessOrder::class);
 * @example
 * // Get dashboard for all cached actions
 * $dashboard = ActionCache::dashboard();
 * // Returns: [
 * //     'total_cached_actions' => 5,
 * //     'actions' => [...],
 * // ]
 * @example
 * // Warm up cache for specific actions
 * ActionCache::warmup([
 *     ProcessOrder::class,
 *     GenerateReport::class,
 *     SendEmail::class,
 * ]);
 * @example
 * // Clear cache for specific action
 * ActionCache::clear(ProcessOrder::class);
 * @example
 * // Clear cache for all actions
 * ActionCache::clearAll();
 */
class ActionCache
{
    /**
     * Get cache status for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Cache status
     */
    public static function getStatus(string $actionClass): array
    {
        // This would need to be implemented based on how caching works
        // For now, return basic structure
        return [
            'action' => $actionClass,
            'cached' => false,
            'cache_keys' => [],
            'ttl' => null,
        ];
    }

    /**
     * Get dashboard data for all cached actions.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::getByTrait(AsCachedResult::class);
        $cached = collect();

        foreach ($actions as $actionClass) {
            $status = static::getStatus($actionClass);
            $cached->push($status);
        }

        return [
            'total_cached_actions' => $cached->count(),
            'actions' => $cached->toArray(),
        ];
    }

    /**
     * Warm up cache for specific actions.
     *
     * @param  array<string>  $actionClasses  Action class names
     */
    public static function warmup(array $actionClasses): void
    {
        foreach ($actionClasses as $actionClass) {
            if (class_exists($actionClass)) {
                // Warm up logic would go here
                // This is a placeholder
            }
        }
    }

    /**
     * Clear cache for an action.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function clear(string $actionClass): void
    {
        if (in_array(AsCachedResult::class, class_uses_recursive($actionClass))) {
            $instance = app($actionClass);
            if (method_exists($instance, 'clearAllCache')) {
                $instance->clearAllCache();
            }
        }
    }

    /**
     * Clear cache for all actions.
     */
    public static function clearAll(): void
    {
        $actions = ActionRegistry::getByTrait(AsCachedResult::class);

        foreach ($actions as $actionClass) {
            static::clear($actionClass);
        }
    }
}
