<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Log;

/**
 * Logger Decorator
 *
 * Automatically logs action execution, parameters, and results.
 * This decorator intercepts handle() calls and logs execution details.
 *
 * Features:
 * - Automatic logging of action start, success, and failure
 * - Parameter sanitization for sensitive data
 * - Execution duration tracking
 * - Configurable log channels and levels
 * - Exception logging with full stack traces
 * - Result sanitization for large objects
 *
 * How it works:
 * 1. When an action uses AsLogger, LoggerDesignPattern recognizes it
 * 2. ActionManager wraps the action with LoggerDecorator
 * 3. When handle() is called, the decorator:
 *    - Logs action start with sanitized parameters
 *    - Executes the action
 *    - Logs success with result and duration
 *    - On exception, logs failure with exception details
 *    - Returns the result (or re-throws exception)
 */
class LoggerDecorator
{
    use DecorateActions;

    protected ?float $logStartTime = null;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with logging.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \Throwable
     */
    public function handle(...$arguments)
    {
        $this->logStartTime = microtime(true);
        $this->logActionStart($arguments);

        try {
            $result = $this->action->handle(...$arguments);
            $this->logActionSuccess($arguments, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->logActionFailure($arguments, $e);
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
     * Log action start.
     */
    protected function logActionStart(array $arguments): void
    {
        $channel = $this->getLogChannel();
        $level = $this->getLogLevel();

        Log::channel($channel)->{$level}('Action started', [
            'action' => get_class($this->action),
            'parameters' => $this->sanitizeParameters($arguments),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log action success.
     */
    protected function logActionSuccess(array $arguments, $result): void
    {
        $channel = $this->getLogChannel();
        $level = $this->getLogLevel();
        $duration = $this->getExecutionDuration();

        Log::channel($channel)->{$level}('Action completed', [
            'action' => get_class($this->action),
            'parameters' => $this->sanitizeParameters($arguments),
            'result' => $this->sanitizeResult($result),
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log action failure.
     */
    protected function logActionFailure(array $arguments, \Throwable $exception): void
    {
        $channel = $this->getLogChannel();

        Log::channel($channel)->error('Action failed', [
            'action' => get_class($this->action),
            'parameters' => $this->sanitizeParameters($arguments),
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'duration_ms' => round($this->getExecutionDuration() * 1000, 2),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get the log channel to use.
     */
    protected function getLogChannel(): string
    {
        return $this->fromActionMethodOrProperty('getLogChannel', 'logChannel', config('logging.default', 'stack'));
    }

    /**
     * Get the log level to use.
     */
    protected function getLogLevel(): string
    {
        return $this->fromActionMethodOrProperty('getLogLevel', 'logLevel', 'info');
    }

    /**
     * Get sensitive parameters that should be redacted.
     */
    protected function getSensitiveParameters(): array
    {
        return $this->fromActionMethod('getSensitiveParameters', ['password', 'password_confirmation', 'token', 'secret', 'api_key']);
    }

    /**
     * Sanitize parameters by redacting sensitive data.
     */
    protected function sanitizeParameters(array $arguments): array
    {
        $sensitive = $this->getSensitiveParameters();

        return array_map(function ($arg) use ($sensitive) {
            if (is_array($arg)) {
                return $this->sanitizeArray($arg, $sensitive);
            }

            if (is_object($arg)) {
                return get_class($arg).' (object)';
            }

            return $arg;
        }, $arguments);
    }

    /**
     * Recursively sanitize array data.
     */
    protected function sanitizeArray(array $data, array $sensitive): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $sensitive))) {
                $data[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeArray($value, $sensitive);
            }
        }

        return $data;
    }

    /**
     * Sanitize result for logging.
     */
    protected function sanitizeResult($result)
    {
        if (is_object($result)) {
            return get_class($result).' (object)';
        }

        if (is_array($result) && count($result) > 100) {
            return 'Array ('.count($result).' items)';
        }

        return $result;
    }

    /**
     * Get execution duration in seconds.
     */
    protected function getExecutionDuration(): float
    {
        return $this->logStartTime ? microtime(true) - $this->logStartTime : 0;
    }
}
