<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Decorator that implements circuit breaker pattern to prevent cascading failures.
 *
 * This decorator automatically tracks failures and opens the circuit when the
 * failure threshold is reached, preventing further calls until the timeout expires.
 */
class CircuitBreakerDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setCircuitBreakerDecorator')) {
            $action->setCircuitBreakerDecorator($this);
        } elseif (property_exists($action, '_circuitBreakerDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_circuitBreakerDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        $key = $this->getCircuitBreakerKey();

        if ($this->isCircuitOpen($key)) {
            return $this->onCircuitOpen($key, ...$arguments);
        }

        try {
            $result = $this->callMethod('handle', $arguments);
            $this->recordSuccess($key);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($key);

            throw $e;
        }
    }

    /**
     * Check if the circuit breaker is open.
     */
    protected function isCircuitOpen(string $key): bool
    {
        $state = Cache::get($key, [
            'failures' => 0,
            'last_failure' => null,
            'state' => 'closed', // closed, open, half-open
        ]);

        if ($state['state'] === 'open') {
            $timeout = $this->getTimeoutSeconds();

            if ($state['last_failure'] && (time() - $state['last_failure']) < $timeout) {
                return true;
            }

            // Timeout expired, move to half-open
            $state['state'] = 'half-open';
            Cache::put($key, $state, $this->getCircuitBreakerTtl());
        }

        return false;
    }

    /**
     * Record a successful execution.
     */
    protected function recordSuccess(string $key): void
    {
        $state = Cache::get($key, [
            'failures' => 0,
            'last_failure' => null,
            'state' => 'closed',
        ]);

        if ($state['state'] === 'half-open') {
            // Success in half-open state, close circuit
            $state['state'] = 'closed';
            $state['failures'] = 0;
        } else {
            // Reset failure count on success
            $state['failures'] = 0;
        }

        Cache::put($key, $state, $this->getCircuitBreakerTtl());
    }

    /**
     * Record a failed execution.
     */
    protected function recordFailure(string $key): void
    {
        $state = Cache::get($key, [
            'failures' => 0,
            'last_failure' => null,
            'state' => 'closed',
        ]);

        $state['failures']++;
        $state['last_failure'] = time();

        if ($state['failures'] >= $this->getFailureThreshold()) {
            $state['state'] = 'open';
        }

        Cache::put($key, $state, $this->getCircuitBreakerTtl());
    }

    /**
     * Handle when circuit is open.
     */
    protected function onCircuitOpen(string $key, ...$arguments)
    {
        if ($this->hasMethod('onCircuitOpen')) {
            return $this->callMethod('onCircuitOpen', array_merge([$key], $arguments));
        }

        throw new \RuntimeException('Circuit breaker is open. Service unavailable.');
    }

    /**
     * Get the circuit breaker cache key.
     */
    protected function getCircuitBreakerKey(): string
    {
        if ($this->hasMethod('getCircuitBreakerKey')) {
            return $this->callMethod('getCircuitBreakerKey');
        }

        return 'circuit_breaker:'.get_class($this->action);
    }

    /**
     * Get the failure threshold.
     */
    protected function getFailureThreshold(): int
    {
        return $this->fromActionMethodOrProperty(
            'getFailureThreshold',
            'failureThreshold',
            5
        );
    }

    /**
     * Get the timeout in seconds.
     */
    protected function getTimeoutSeconds(): int
    {
        return $this->fromActionMethodOrProperty(
            'getTimeoutSeconds',
            'timeoutSeconds',
            60
        );
    }

    /**
     * Get the circuit breaker TTL.
     */
    protected function getCircuitBreakerTtl(): int
    {
        return $this->getTimeoutSeconds() * 2;
    }

    /**
     * Get the current circuit breaker state.
     */
    public function getCircuitState(): array
    {
        $key = $this->getCircuitBreakerKey();

        return Cache::get($key, [
            'failures' => 0,
            'last_failure' => null,
            'state' => 'closed',
        ]);
    }

    /**
     * Reset the circuit breaker.
     */
    public function resetCircuit(): void
    {
        $key = $this->getCircuitBreakerKey();
        Cache::forget($key);
    }
}
