<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use App\Jobs\ExecuteDebouncedAction;
use Illuminate\Support\Facades\Cache;

/**
 * Decorator that debounces rapid calls to actions.
 *
 * Supports both queue and scheduler execution methods:
 * - Queue: Dispatches a job to the queue with delay
 * - Scheduler: Uses Laravel's scheduler to execute at the scheduled time
 * - Custom: Allows action to define custom execution via executeDebounced method
 */
class DebounceDecorator
{
    use DecorateActions;

    /**
     * Execution method constants
     */
    public const METHOD_QUEUE = 'queue';

    public const METHOD_SCHEDULER = 'scheduler';

    public const METHOD_CUSTOM = 'custom';

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $key = $this->getDebounceKey(...$arguments);
        $delay = $this->getDebounceDelay();
        $cacheKey = $this->getCacheKey($key);

        // Store arguments
        Cache::put($cacheKey, [
            'arguments' => $arguments,
            'scheduled_at' => now()->addMilliseconds($delay),
        ], ($delay / 1000) + 60);

        // Check if should execute now
        $pending = Cache::get($cacheKey);

        if ($pending && now()->greaterThan($pending['scheduled_at'])) {
            Cache::forget($cacheKey);

            return $this->callMethod('handle', $pending['arguments']);
        }

        // Schedule for later
        $this->scheduleExecution($key, $delay, $arguments, $cacheKey);

        return null;
    }

    /**
     * Schedule execution using the configured method (queue, scheduler, or custom).
     */
    protected function scheduleExecution(string $key, int $delay, array $arguments, string $cacheKey): void
    {
        $method = $this->getExecutionMethod();

        match ($method) {
            self::METHOD_QUEUE => $this->scheduleViaQueue($cacheKey, $delay, $arguments),
            self::METHOD_SCHEDULER => $this->scheduleViaScheduler($cacheKey, $delay, $arguments),
            self::METHOD_CUSTOM => $this->scheduleViaCustom($key, $delay, $arguments),
            default => $this->scheduleViaQueue($cacheKey, $delay, $arguments), // Default to queue
        };
    }

    /**
     * Schedule execution via queue.
     */
    protected function scheduleViaQueue(string $cacheKey, int $delay, array $arguments): void
    {
        ExecuteDebouncedAction::dispatch(
            get_class($this->action),
            $cacheKey,
            $arguments
        )->delay(now()->addMilliseconds($delay));
    }

    /**
     * Schedule execution via Laravel scheduler.
     *
     * Note: For debouncing, queue with delay is more practical than scheduler.
     * Laravel's scheduler is designed for recurring tasks, not one-off delayed execution.
     * This method uses queue with delay for practical debouncing.
     *
     * For true scheduler-based execution (e.g., using a scheduled command that
     * processes pending debounced actions), implement a custom executeDebounced
     * method on your action.
     */
    protected function scheduleViaScheduler(string $cacheKey, int $delay, array $arguments): void
    {
        // For debouncing, queue with delay is the most practical approach
        // Scheduler is better suited for recurring tasks, not one-off delayed execution
        $this->scheduleViaQueue($cacheKey, $delay, $arguments);
    }

    /**
     * Schedule execution via custom method defined on the action.
     */
    protected function scheduleViaCustom(string $key, int $delay, array $arguments): void
    {
        if ($this->hasMethod('executeDebounced')) {
            $this->callMethod('executeDebounced', [$key, $delay, $arguments]);
        }
    }

    /**
     * Get the execution method to use.
     *
     * Priority:
     * 1. Action's getDebounceExecutionMethod() method
     * 2. Action's $debounceExecutionMethod property
     * 3. Default to queue
     */
    protected function getExecutionMethod(): string
    {
        return $this->fromActionMethodOrProperty(
            'getDebounceExecutionMethod',
            'debounceExecutionMethod',
            self::METHOD_QUEUE
        );
    }

    protected function getDebounceKey(...$arguments): string
    {
        if ($this->hasMethod('getDebounceKey')) {
            return $this->callMethod('getDebounceKey', $arguments);
        }

        return hash('sha256', serialize($arguments));
    }

    protected function getCacheKey(string $key): string
    {
        return 'debounce:'.get_class($this->action).':'.$key;
    }

    protected function getDebounceDelay(): int
    {
        return $this->fromActionMethod('getDebounceDelay', [], 1000);
    }
}
