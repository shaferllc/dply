<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Lazy Decorator
 *
 * Defers action execution until result is actually needed (lazy evaluation).
 * This decorator wraps the action and only executes it when the result is accessed.
 *
 * Features:
 * - Lazy evaluation - execution deferred until needed
 * - Result caching - executes only once, caches result
 * - Memory efficient - doesn't execute until accessed
 * - Supports get() method for explicit execution
 * - Supports isExecuted() to check execution status
 * - Supports reset() to clear cached result
 *
 * How it works:
 * 1. When an action uses AsLazy, LazyDesignPattern recognizes it
 * 2. ActionManager wraps the action with LazyDecorator
 * 3. When handle() is called, the decorator stores arguments but doesn't execute
 * 4. Execution happens when:
 *    - get() is called explicitly
 *    - Result is accessed (via magic methods)
 *    - Result is used in operations
 */
class LazyDecorator
{
    use DecorateActions;

    protected mixed $lazyResult = null;

    protected bool $lazyExecuted = false;

    protected array $lazyArguments = [];

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action lazily - stores arguments and executes on first call.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        if (! $this->lazyExecuted) {
            $this->lazyArguments = $arguments;
            $this->lazyResult = $this->action->handle(...$arguments);
            $this->lazyExecuted = true;
        }

        return $this->lazyResult;
    }

    /**
     * Get the cached result, executing if not already executed.
     *
     * @return mixed
     */
    public function get()
    {
        if (! $this->lazyExecuted && ! empty($this->lazyArguments)) {
            $this->lazyResult = $this->action->handle(...$this->lazyArguments);
            $this->lazyExecuted = true;
        }

        return $this->lazyResult;
    }

    /**
     * Make the decorator callable - executes lazily.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Check if the action has been executed.
     */
    public function isExecuted(): bool
    {
        return $this->lazyExecuted;
    }

    /**
     * Reset the lazy state, allowing re-execution.
     */
    public function reset(): void
    {
        $this->lazyResult = null;
        $this->lazyExecuted = false;
        $this->lazyArguments = [];
    }

    /**
     * Get the stored arguments.
     */
    public function getArguments(): array
    {
        return $this->lazyArguments;
    }

    /**
     * Magic method to access result properties.
     */
    public function __get(string $name)
    {
        $result = $this->get();

        if (is_object($result)) {
            return $result->{$name};
        }

        return null;
    }

    /**
     * Magic method to call result methods.
     */
    public function __call(string $method, array $arguments)
    {
        $result = $this->get();

        if (is_object($result) && method_exists($result, $method)) {
            return $result->{$method}(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on lazy result.");
    }
}
