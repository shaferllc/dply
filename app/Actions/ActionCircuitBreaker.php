<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\AsCircuitBreaker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Action Circuit Breaker Dashboard - Monitor circuit breaker states.
 *
 * Provides dashboard and management capabilities for circuit breakers.
 *
 * @example
 * // Get circuit breaker status for an action
 * $status = ActionCircuitBreaker::getStatus(ProcessOrder::class);
 * // Returns: [
 * //     'action' => 'App\Actions\ProcessOrder',
 * //     'state' => 'closed', // closed, open, half-open
 * //     'failures' => 0,
 * //     'last_failure' => null,
 * //     'threshold' => 5,
 * //     'timeout' => 60,
 * // ]
 * @example
 * // Get dashboard for all circuit breakers
 * $dashboard = ActionCircuitBreaker::dashboard();
 * // Returns: [
 * //     'total_circuits' => 10,
 * //     'open_circuits' => 2,
 * //     'half_open_circuits' => 1,
 * //     'closed_circuits' => 7,
 * //     'circuits' => [...],
 * // ]
 * @example
 * // Reset circuit breaker
 * ActionCircuitBreaker::reset(ProcessOrder::class);
 * @example
 * // Reset all circuit breakers
 * ActionCircuitBreaker::resetAll();
 * @example
 * // Get open circuits
 * $open = ActionCircuitBreaker::getOpenCircuits();
 */
class ActionCircuitBreaker
{
    /**
     * Get circuit breaker status for an action.
     *
     * @param  string  $actionClass  Action class name
     * @return array<string, mixed> Circuit breaker status
     */
    public static function getStatus(string $actionClass): array
    {
        $key = static::getCircuitBreakerKey($actionClass);
        $state = Cache::get($key, [
            'failures' => 0,
            'last_failure' => null,
            'state' => 'closed',
        ]);

        return [
            'action' => $actionClass,
            'state' => $state['state'],
            'failures' => $state['failures'],
            'last_failure' => $state['last_failure'] ? date('Y-m-d H:i:s', $state['last_failure']) : null,
            'threshold' => static::getFailureThreshold($actionClass),
            'timeout' => static::getTimeoutSeconds($actionClass),
        ];
    }

    /**
     * Get dashboard data for all circuit breakers.
     *
     * @return array<string, mixed> Dashboard data
     */
    public static function dashboard(): array
    {
        $actions = ActionRegistry::getByTrait(AsCircuitBreaker::class);
        $circuits = collect();
        $openCount = 0;
        $halfOpenCount = 0;
        $closedCount = 0;

        foreach ($actions as $actionClass) {
            $status = static::getStatus($actionClass);
            $circuits->push($status);

            match ($status['state']) {
                'open' => $openCount++,
                'half-open' => $halfOpenCount++,
                'closed' => $closedCount++,
                default => null,
            };
        }

        return [
            'total_circuits' => $circuits->count(),
            'open_circuits' => $openCount,
            'half_open_circuits' => $halfOpenCount,
            'closed_circuits' => $closedCount,
            'circuits' => $circuits->sortByDesc('failures')->values()->toArray(),
        ];
    }

    /**
     * Get open circuit breakers.
     *
     * @return Collection<array> Open circuit breakers
     */
    public static function getOpenCircuits(): Collection
    {
        $actions = ActionRegistry::getByTrait(AsCircuitBreaker::class);

        return collect($actions)
            ->map(fn ($action) => static::getStatus($action))
            ->filter(fn ($status) => $status['state'] === 'open');
    }

    /**
     * Reset circuit breaker for an action.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function reset(string $actionClass): void
    {
        $key = static::getCircuitBreakerKey($actionClass);
        Cache::put($key, [
            'failures' => 0,
            'last_failure' => null,
            'state' => 'closed',
        ], 86400);
    }

    /**
     * Reset all circuit breakers.
     */
    public static function resetAll(): void
    {
        $actions = ActionRegistry::getByTrait(AsCircuitBreaker::class);

        foreach ($actions as $actionClass) {
            static::reset($actionClass);
        }
    }

    /**
     * Get circuit breaker cache key.
     */
    protected static function getCircuitBreakerKey(string $actionClass): string
    {
        return "circuit_breaker:{$actionClass}";
    }

    /**
     * Get failure threshold for an action.
     */
    protected static function getFailureThreshold(string $actionClass): int
    {
        if (class_exists($actionClass)) {
            $instance = app($actionClass);
            if (method_exists($instance, 'getFailureThreshold')) {
                return $instance->getFailureThreshold();
            }
        }

        return 5; // Default
    }

    /**
     * Get timeout seconds for an action.
     */
    protected static function getTimeoutSeconds(string $actionClass): int
    {
        if (class_exists($actionClass)) {
            $instance = app($actionClass);
            if (method_exists($instance, 'getTimeoutSeconds')) {
                return $instance->getTimeoutSeconds();
            }
        }

        return 60; // Default
    }
}
