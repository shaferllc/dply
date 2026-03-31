<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Idempotent Decorator
 *
 * Ensures actions are idempotent - safe to retry without side effects.
 * This decorator intercepts handle() calls and caches results to prevent
 * duplicate execution of the same operation.
 *
 * Features:
 * - Automatic idempotency key generation
 * - Result caching to prevent duplicate execution
 * - Customizable key generation
 * - Configurable TTL
 * - Returns cached results on duplicate calls
 *
 * How it works:
 * 1. When an action uses AsIdempotent, IdempotentDesignPattern recognizes it
 * 2. ActionManager wraps the action with IdempotentDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates an idempotency key from arguments
 *    - Checks if result is already cached
 *    - Returns cached result if found (idempotent)
 *    - Executes action if not cached
 *    - Caches the result for future calls
 *    - Returns the result
 *
 * Benefits:
 * - Prevents duplicate execution
 * - Safe to retry operations
 * - Returns cached results instantly
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 */
class IdempotentDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with idempotency protection.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $key = $this->getIdempotencyKey(...$arguments);
        $cacheKey = $this->getIdempotencyCacheKey($key);

        // Check if this exact operation was already executed
        if (Cache::has($cacheKey)) {
            return $this->handleIdempotentHit($cacheKey, $arguments);
        }

        // Execute and cache result
        $result = $this->action->handle(...$arguments);
        $this->cacheIdempotentResult($cacheKey, $result);

        return $result;
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Handle idempotent cache hit.
     */
    protected function handleIdempotentHit(string $cacheKey, array $arguments): mixed
    {
        $cached = Cache::get($cacheKey);

        // Return cached result if available
        if (isset($cached['result'])) {
            return $cached['result'];
        }

        // If result was null, return null (idempotent)
        return null;
    }

    /**
     * Cache the idempotent result.
     */
    protected function cacheIdempotentResult(string $cacheKey, mixed $result): void
    {
        Cache::put($cacheKey, [
            'result' => $result,
            'executed_at' => now()->toIso8601String(),
        ], $this->getIdempotencyTtl());
    }

    /**
     * Get the idempotency key from arguments.
     */
    protected function getIdempotencyKey(...$arguments): string
    {
        return $this->fromActionMethod('getIdempotencyKey', $arguments, $this->getDefaultIdempotencyKey(...$arguments));
    }

    /**
     * Get the default idempotency key (hash of arguments).
     */
    protected function getDefaultIdempotencyKey(...$arguments): string
    {
        return hash('sha256', serialize($arguments));
    }

    /**
     * Get the cache key for idempotency.
     */
    protected function getIdempotencyCacheKey(string $key): string
    {
        return 'idempotent:'.get_class($this->action).':'.$key;
    }

    /**
     * Get the TTL for idempotency cache.
     */
    protected function getIdempotencyTtl(): int
    {
        return $this->fromActionMethodOrProperty('getIdempotencyTtl', 'idempotencyTtl', 3600);
    }
}
