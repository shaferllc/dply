<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Debuggable Decorator
 *
 * Provides enhanced debugging capabilities in development mode by automatically
 * dumping input parameters, return values, execution paths, and performance metrics.
 *
 * Features:
 * - Automatic debugging in local/testing environments
 * - Input parameter dumping
 * - Return value inspection
 * - Performance metrics (memory, duration)
 * - Exception debugging with stack traces
 * - Configurable via config file
 * - Zero overhead in production
 *
 * How it works:
 * 1. When an action uses AsDebuggable, DebuggableDesignPattern recognizes it
 * 2. ActionManager wraps the action with DebuggableDecorator
 * 3. When handle() is called, the decorator:
 *    - Checks if debugging should be enabled
 *    - Dumps input parameters and metadata
 *    - Executes the action
 *    - Dumps return value and performance metrics
 *    - On exception, dumps exception details
 *    - Returns the result (or re-throws exception)
 */
class DebuggableDecorator
{
    use DecorateActions;

    protected ?float $timeStart = null;

    protected ?int $memoryStart = null;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with debugging.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Throwable
     */
    public function handle(...$arguments)
    {
        if (! $this->shouldDebug()) {
            return $this->callMethod('handle', $arguments);
        }

        $this->debugStart($arguments);

        try {
            $result = $this->callMethod('handle', $arguments);
            $this->debugSuccess($arguments, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->debugFailure($arguments, $e);

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
     * Check if debugging should be enabled.
     */
    protected function shouldDebug(): bool
    {
        if ($this->hasMethod('shouldDebug')) {
            return $this->callMethod('shouldDebug');
        }

        return app()->environment(['local', 'testing']) && config('actions.debug.enabled', true);
    }

    /**
     * Debug action start.
     */
    protected function debugStart(array $arguments): void
    {
        $this->timeStart = microtime(true);
        $this->memoryStart = memory_get_usage(true);

        if (function_exists('dump')) {
            dump([
                'action' => get_class($this->action),
                'method' => 'handle',
                'arguments' => $arguments,
                'memory_before' => $this->memoryStart,
                'time_start' => $this->timeStart,
            ]);
        }
    }

    /**
     * Debug successful execution.
     */
    protected function debugSuccess(array $arguments, $result): void
    {
        $timeEnd = microtime(true);
        $memoryEnd = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $duration = ($timeEnd - $this->timeStart) * 1000; // Convert to milliseconds

        if (function_exists('dump')) {
            dump([
                'action' => get_class($this->action),
                'result' => $result,
                'memory_after' => $memoryEnd,
                'memory_used' => $memoryEnd - $this->memoryStart,
                'memory_peak' => $memoryPeak,
                'duration_ms' => round($duration, 2),
                'time_end' => $timeEnd,
            ]);
        }
    }

    /**
     * Debug failure/exception.
     */
    protected function debugFailure(array $arguments, \Throwable $exception): void
    {
        $timeEnd = microtime(true);
        $duration = ($timeEnd - ($this->timeStart ?? microtime(true))) * 1000;

        if (function_exists('dump')) {
            dump([
                'action' => get_class($this->action),
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'arguments' => $arguments,
                'duration_ms' => round($duration, 2),
                'trace' => $exception->getTraceAsString(),
            ]);
        }
    }
}
