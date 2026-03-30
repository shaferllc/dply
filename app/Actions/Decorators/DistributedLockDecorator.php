<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Distributed Lock Decorator
 *
 * Provides distributed locking across multiple servers/processes with enhanced features.
 * This decorator intercepts handle() calls and ensures only one instance can run
 * at a time for a given lock key, with support for Redis fallback and detailed lock metadata.
 *
 * Features:
 * - Distributed locking across multiple servers/processes
 * - Automatic lock release (even on exceptions)
 * - Configurable lock keys and timeouts
 * - Redis fallback for better reliability
 * - Lock metadata tracking (process ID, server, timestamp)
 * - Prevents race conditions in concurrent environments
 * - TTL slightly longer than timeout to prevent race conditions
 *
 * How it works:
 * 1. When an action uses AsDistributedLock, DistributedLockDesignPattern recognizes it
 * 2. ActionManager wraps the action with DistributedLockDecorator
 * 3. When handle() is called, the decorator:
 *    - Attempts to acquire a distributed lock (Cache or Redis)
 *    - If lock cannot be acquired, throws RuntimeException
 *    - Executes the action within the lock
 *    - Automatically releases lock after execution
 *    - Returns the result (or re-throws exception)
 */
class DistributedLockDecorator
{
    use DecorateActions;

    protected ?string $lockKey = null;

    protected ?bool $lockAcquired = false;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with distributed locking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public function handle(...$arguments)
    {
        $this->lockKey = $this->getLockKey(...$arguments);

        if (! $this->acquireLock()) {
            throw new \RuntimeException('Could not acquire distributed lock. Another process may be running.');
        }

        try {
            return $this->callMethod('handle', $arguments);
        } finally {
            $this->releaseLock();
        }
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
     * Acquire the distributed lock.
     */
    protected function acquireLock(): bool
    {
        $timeout = $this->getLockTimeout();
        $ttl = $this->getLockTtl();

        // Try to acquire lock using Laravel Cache lock
        $lock = Cache::lock($this->lockKey, $timeout);

        $acquired = $lock->get(function () {
            $this->lockAcquired = true;

            return true;
        });

        if (! $acquired) {
            // Fallback: try Redis SET NX EX for better reliability
            if (config('cache.default') === 'redis') {
                $acquired = Redis::set($this->lockKey, $this->getLockValue(), 'EX', $ttl, 'NX') === true;
                $this->lockAcquired = $acquired;
            }
        }

        return $this->lockAcquired;
    }

    /**
     * Release the distributed lock.
     */
    protected function releaseLock(): void
    {
        if ($this->lockAcquired && $this->lockKey) {
            // Release Laravel Cache lock
            Cache::lock($this->lockKey)->release();

            // Fallback: Redis delete
            if (config('cache.default') === 'redis') {
                Redis::del($this->lockKey);
            }

            $this->lockAcquired = false;
        }
    }

    /**
     * Get the lock key to use.
     */
    protected function getLockKey(...$arguments): string
    {
        if ($this->hasMethod('getLockKey')) {
            return $this->callMethod('getLockKey', $arguments);
        }

        // Default: use class name + hashed arguments
        $key = 'lock:'.get_class($this->action).':'.hash('sha256', serialize($arguments));

        return $key;
    }

    /**
     * Get the lock timeout in seconds.
     */
    protected function getLockTimeout(): int
    {
        if ($this->hasMethod('getLockTimeout')) {
            return $this->callMethod('getLockTimeout');
        }

        if ($this->hasProperty('lockTimeout')) {
            return $this->getProperty('lockTimeout');
        }

        return 60; // 1 minute default
    }

    /**
     * Get the lock TTL in seconds.
     *
     * TTL should be slightly longer than timeout to prevent race conditions.
     */
    protected function getLockTtl(): int
    {
        return $this->getLockTimeout() + 10;
    }

    /**
     * Get the lock value (metadata stored in Redis).
     */
    protected function getLockValue(): string
    {
        return json_encode([
            'process_id' => getmypid(),
            'server' => gethostname(),
            'acquired_at' => now()->toIso8601String(),
        ]);
    }
}
