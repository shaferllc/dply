<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Decorator that caches action results based on input hash.
 *
 * This decorator automatically caches the results of action execution
 * and reuses cached results for identical inputs, improving performance.
 */
class CachedResultDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
        // Inject decorator reference into action so trait methods can access it
        if (method_exists($action, 'setCachedResultDecorator')) {
            $action->setCachedResultDecorator($this);
        } elseif (property_exists($action, '_cachedResultDecorator')) {
            $reflection = new \ReflectionClass($action);
            $property = $reflection->getProperty('_cachedResultDecorator');
            $property->setAccessible(true);
            $property->setValue($action, $this);
        }
    }

    public function handle(...$arguments)
    {
        $cacheKey = $this->getCacheKey(...$arguments);
        $ttl = $this->getCacheTtl();

        return Cache::remember($cacheKey, $ttl, function () use ($arguments) {
            return $this->callMethod('handle', $arguments);
        });
    }

    /**
     * Get the cache key for the given arguments.
     */
    protected function getCacheKey(...$arguments): string
    {
        if ($this->hasMethod('getCacheKey')) {
            return $this->callMethod('getCacheKey', $arguments);
        }

        // Default: hash all arguments
        return 'cached_result:'.get_class($this->action).':'.hash('sha256', serialize($arguments));
    }

    /**
     * Get the cache TTL in seconds.
     */
    protected function getCacheTtl(): int
    {
        return $this->fromActionMethodOrProperty(
            'getCacheTtl',
            'cacheTtl',
            3600 // 1 hour default
        );
    }

    /**
     * Forget cached result for specific arguments.
     */
    public function forgetCache(...$arguments): void
    {
        $cacheKey = $this->getCacheKey(...$arguments);
        Cache::forget($cacheKey);
    }

    /**
     * Clear all cached results for this action.
     *
     * Note: This requires a cache driver that supports tags or patterns.
     * For Redis with tags: Cache::tags([get_class($this->action)])->flush();
     */
    public function clearAllCache(): void
    {
        // Try to use tags if available (Redis)
        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags([get_class($this->action)])->flush();
        } else {
            // Fallback: clear all cache (use with caution)
            Cache::flush();
        }
    }
}
