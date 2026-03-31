<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Collection;

/**
 * Action Health Checks - Monitor action health and status.
 *
 * Provides health checking capabilities for actions, including
 * availability, performance, and error rate monitoring.
 *
 * @example
 * // Check health of specific action
 * $health = ActionHealth::check(ProcessOrder::class);
 * // Returns: [
 * //     'healthy' => true,
 * //     'status' => 'healthy',
 * //     'metrics' => [...],
 * //     'issues' => [],
 * //     'checked_at' => '2024-01-15T10:30:00Z',
 * // ]
 *
 * if (! $health['healthy']) {
 *     foreach ($health['issues'] as $issue) {
 *         \Log::warning("Health issue: {$issue}");
 *     }
 * }
 * @example
 * // Check health of all actions
 * $allHealth = ActionHealth::checkAll();
 * // Returns: Collection of health status for all actions
 * @example
 * // Get unhealthy actions
 * $unhealthy = ActionHealth::getUnhealthyActions();
 * foreach ($unhealthy as $action) {
 *     \Log::error("Unhealthy action: {$action['action']}", [
 *         'issues' => $action['issues'],
 *     ]);
 * }
 * @example
 * // Get system health overview
 * $systemHealth = ActionHealth::getSystemHealth();
 * // Returns: [
 * //     'overall' => 'healthy', // or 'degraded'
 * //     'total_actions' => 50,
 * //     'healthy_actions' => 48,
 * //     'unhealthy_actions' => 2,
 * //     'health_percentage' => 96.0,
 * //     'checked_at' => '2024-01-15T10:30:00Z',
 * // ]
 * @example
 * // Schedule health checks
 * // In app/Console/Kernel.php or routes/console.php:
 * \Schedule::call(function () {
 *     $unhealthy = ActionHealth::getUnhealthyActions();
 *
 *     if ($unhealthy->isNotEmpty()) {
 *         \Notification::route('slack', '#alerts')
 *             ->notify(new UnhealthyActionsNotification($unhealthy));
 *     }
 * })->hourly();
 */
class ActionHealth
{
    /**
     * Check health of a specific action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Health status
     */
    public static function check(string $actionClass): array
    {
        $metrics = ActionMetrics::getMetrics($actionClass);
        $isHealthy = true;
        $issues = [];

        // Check if action exists
        if (! class_exists($actionClass)) {
            return [
                'healthy' => false,
                'status' => 'not_found',
                'issues' => ["Action class '{$actionClass}' does not exist"],
            ];
        }

        // Check failure rate
        if ($metrics['calls'] > 0) {
            $failureRate = $metrics['failures'] / $metrics['calls'];
            if ($failureRate > 0.1) { // More than 10% failure rate
                $isHealthy = false;
                $issues[] = 'High failure rate: '.($failureRate * 100).'%';
            }
        }

        // Check average duration (if threshold is set)
        $alert = ActionMetrics::checkAlert($actionClass);
        if ($alert) {
            $isHealthy = false;
            $issues[] = 'Action exceeds performance threshold';
        }

        return [
            'healthy' => $isHealthy,
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'metrics' => $metrics,
            'issues' => $issues,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Check health of all actions.
     *
     * @return Collection<array> Health status for all actions
     */
    public static function checkAll(): Collection
    {
        $actions = ActionRegistry::all();
        $results = collect();

        foreach ($actions as $actionClass) {
            $results->push([
                'action' => $actionClass,
                ...static::check($actionClass),
            ]);
        }

        return $results;
    }

    /**
     * Get unhealthy actions.
     *
     * @return Collection<array> Unhealthy actions
     */
    public static function getUnhealthyActions(): Collection
    {
        return static::checkAll()->filter(fn ($result) => ! $result['healthy']);
    }

    /**
     * Get overall system health.
     *
     * @return array<string, mixed> Overall health status
     */
    public static function getSystemHealth(): array
    {
        $allChecks = static::checkAll();
        $healthy = $allChecks->filter(fn ($check) => $check['healthy'])->count();
        $unhealthy = $allChecks->filter(fn ($check) => ! $check['healthy'])->count();
        $total = $allChecks->count();

        return [
            'overall' => $unhealthy === 0 ? 'healthy' : 'degraded',
            'total_actions' => $total,
            'healthy_actions' => $healthy,
            'unhealthy_actions' => $unhealthy,
            'health_percentage' => $total > 0 ? ($healthy / $total) * 100 : 100,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
