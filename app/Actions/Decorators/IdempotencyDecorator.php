<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Decorator that ensures actions are idempotent.
 */
class IdempotencyDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $key = $this->getIdempotencyKey(...$arguments);
        $cacheKey = $this->getCacheKey($key);

        // Check if already executed
        if (Cache::has($cacheKey)) {
            return $this->handleIdempotentHit($cacheKey);
        }

        // Execute and cache
        $result = $this->callMethod('handle', $arguments);
        $this->cacheResult($cacheKey, $result);

        return $result;
    }

    protected function handleIdempotentHit(string $cacheKey): mixed
    {
        $cached = Cache::get($cacheKey);

        return $cached['result'] ?? null;
    }

    protected function cacheResult(string $cacheKey, mixed $result): void
    {
        Cache::put($cacheKey, [
            'result' => $result,
            'executed_at' => now()->toIso8601String(),
        ], $this->getTtl());
    }

    protected function getIdempotencyKey(...$arguments): string
    {
        if ($this->hasMethod('getIdempotencyKey')) {
            return $this->callMethod('getIdempotencyKey', $arguments);
        }

        return hash('sha256', serialize($arguments));
    }

    protected function getCacheKey(string $key): string
    {
        return 'idempotent:'.get_class($this->action).':'.$key;
    }

    protected function getTtl(): int
    {
        return $this->fromActionMethod('getIdempotencyTtl', [], 3600);
    }
}
