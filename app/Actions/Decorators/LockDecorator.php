<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Lock Decorator
 *
 * Prevents concurrent execution using distributed locks.
 * This decorator intercepts handle() calls and ensures only one instance
 * can run at a time for a given lock key.
 *
 * Features:
 * - Distributed locking across multiple servers/processes
 * - Automatic lock release (even on exceptions)
 * - Configurable lock keys and timeouts
 * - Prevents race conditions in concurrent environments
 *
 * How it works:
 * 1. When an action uses AsLock, LockDesignPattern recognizes it
 * 2. ActionManager wraps the action with LockDecorator
 * 3. When handle() is called, the decorator:
 *    - Attempts to acquire a distributed lock
 *    - If lock cannot be acquired, throws RuntimeException
 *    - Executes the action within the lock
 *    - Automatically releases lock after execution
 *    - Returns the result (or re-throws exception)
 */
class LockDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with locking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Throwable
     */
    public function handle(...$arguments)
    {
        $key = $this->getLockKey(...$arguments);
        $timeout = $this->getLockTimeout();

        $lock = Cache::lock($key, $timeout);

        if (! $lock->get()) {
            throw new \RuntimeException('Could not acquire lock. Action may be running elsewhere.');
        }

        try {
            return $this->action->handle(...$arguments);
        } finally {
            $lock->release();
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
     * Get the lock key to use.
     */
    protected function getLockKey(...$arguments): string
    {
        return $this->fromActionMethod('getLockKey', $this->getDefaultLockKey(...$arguments), $arguments);
    }

    /**
     * Get the default lock key if not customized.
     */
    protected function getDefaultLockKey(...$arguments): string
    {
        $class = get_class($this->action);
        $args = serialize($arguments);

        return 'lock:'.$class.':'.md5($args);
    }

    /**
     * Get the lock timeout in seconds.
     */
    protected function getLockTimeout(): int
    {
        return $this->fromActionMethodOrProperty('getLockTimeout', 'lockTimeout', 10);
    }
}
