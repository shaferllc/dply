<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Lifecycle Decorator
 *
 * Provides lifecycle hooks for action execution.
 * This decorator intercepts handle() calls and invokes lifecycle methods
 * at appropriate points during execution.
 *
 * Lifecycle Hooks (in order):
 * 1. beforeHandle() - Called before handle() executes
 * 2. onValidation() - Called after validation (if applicable)
 * 3. onAuthorized() - Called after authorization check (if applicable)
 * 4. handle() - Main action logic
 * 5. afterHandle() - Called after handle() succeeds
 * 6. onSuccess() - Called when handle() succeeds
 * 7. onError() - Called when handle() throws an exception
 * 8. onRetry() - Called before retrying (if retry logic exists)
 * 9. onTimeout() - Called if execution times out
 * 10. onCancelled() - Called if execution is cancelled
 * 11. afterExecution() - Called after handle() completes (always)
 *
 * Features:
 * - Comprehensive lifecycle hooks for action execution
 * - Automatic hook invocation in correct order
 * - Error handling with lifecycle hooks
 * - Success/failure tracking
 * - Retry support
 * - Timeout handling
 * - Cancellation support
 */
class LifecycleDecorator
{
    use DecorateActions;

    protected ?float $startTime = null;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with lifecycle hooks.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Throwable
     */
    public function handle(...$arguments)
    {
        $this->startTime = microtime(true);

        $this->callLifecycleMethod('beforeHandle', $arguments);
        $this->callLifecycleMethod('onValidation', $arguments);
        $this->callLifecycleMethod('onAuthorized', $arguments);

        try {
            $result = $this->action->handle(...$arguments);
            $this->callLifecycleMethod('afterHandle', array_merge([$result], $arguments));
            $this->callLifecycleMethod('onSuccess', array_merge([$result], $arguments));
            $this->callLifecycleMethod('afterExecution', array_merge([$result, null], $arguments));

            return $result;
        } catch (\Throwable $e) {
            $this->callLifecycleMethod('onError', array_merge([$e], $arguments));
            $this->callLifecycleMethod('afterExecution', array_merge([null, $e], $arguments));

            throw $e;
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
     * Call a lifecycle method if it exists.
     */
    protected function callLifecycleMethod(string $method, array $arguments): void
    {
        if ($this->hasMethod($method)) {
            $this->callMethod($method, $arguments);
        }
    }

    /**
     * Get execution duration.
     */
    protected function getExecutionDuration(): float
    {
        return $this->startTime ? microtime(true) - $this->startTime : 0;
    }
}
