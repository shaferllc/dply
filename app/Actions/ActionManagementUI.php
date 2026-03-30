<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * Action Management UI - Foundation for web-based management interface.
 *
 * Provides data aggregation and API endpoints for management UI.
 *
 * @example
 * // Get dashboard data
 * $dashboard = ActionManagementUI::dashboard();
 * @example
 * // Get action details
 * $details = ActionManagementUI::getActionDetails(ProcessOrder::class);
 * @example
 * // Get system overview
 * $overview = ActionManagementUI::getSystemOverview();
 */
class ActionManagementUI
{
    /**
     * Get complete dashboard data.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        return [
            'metrics' => ActionMetrics::dashboard(),
            'health' => ActionHealth::getSystemHealth(),
            'circuit_breakers' => ActionCircuitBreaker::dashboard(),
            'retries' => ActionRetry::dashboard(),
            'queues' => ActionQueue::dashboard(),
            'errors' => ActionErrorTracking::dashboard(),
            'rate_limits' => ActionRateLimits::dashboard(),
            'cache' => ActionCache::dashboard(),
            'versions' => ActionVersions::dashboard(),
        ];
    }

    /**
     * Get detailed information for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Action details
     */
    public static function getActionDetails(string $actionClass): array
    {
        return [
            'metadata' => ActionRegistry::getMetadata($actionClass),
            'metrics' => ActionMetrics::getMetrics($actionClass),
            'health' => ActionHealth::check($actionClass),
            'circuit_breaker' => ActionCircuitBreaker::getStatus($actionClass),
            'retry_stats' => ActionRetry::getStats($actionClass),
            'queue_status' => ActionQueue::getStatus($actionClass),
            'error_summary' => ActionErrorTracking::getSummary($actionClass),
            'dependencies' => ActionRegistry::getDependencies($actionClass),
            'dependents' => ActionRegistry::getDependents($actionClass),
        ];
    }

    /**
     * Get system overview.
     *
     * @return array<string, mixed> System overview
     */
    public static function getSystemOverview(): array
    {
        $actions = ActionRegistry::all();

        return [
            'total_actions' => $actions->count(),
            'active_actions' => $actions->filter(fn ($action) => ActionMetrics::getMetrics($action)['calls'] > 0)->count(),
            'system_health' => ActionHealth::getSystemHealth(),
            'open_circuits' => ActionCircuitBreaker::getOpenCircuits()->count(),
            'failed_jobs' => ActionQueue::dashboard()['total_failed'],
            'total_errors' => ActionErrorTracking::dashboard()['total_errors'],
        ];
    }
}
