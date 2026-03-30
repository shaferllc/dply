<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\TimeoutEnabled;
use App\Actions\Attributes\TimeoutSeconds;
use App\Actions\Concerns\DecorateActions;

/**
 * Timeout Decorator
 *
 * Automatically enforces execution timeouts for actions.
 * This decorator ensures that long-running actions are terminated
 * if they exceed the configured timeout duration.
 *
 * Features:
 * - Automatic timeout enforcement
 * - Support for PCNTL (precise) or timer-based (fallback) timeout
 * - Configurable timeout duration
 * - Timeout metadata in results
 * - Seamless integration with other decorators
 *
 * How it works:
 * 1. When an action uses AsTimeout, TimeoutDesignPattern recognizes it
 * 2. ActionManager wraps the action with TimeoutDecorator
 * 3. When handle() is called, the decorator:
 *    - Gets the timeout duration from action
 *    - Executes the action with timeout enforcement
 *    - Throws TimeoutException if execution exceeds timeout
 *    - Adds timeout metadata to the result
 *
 * Timeout Methods:
 * - PCNTL (preferred): Uses pcntl_alarm for precise timeout enforcement
 * - Timer-based (fallback): Checks elapsed time after execution
 *
 * Timeout Metadata:
 * The result will include a `_timeout` property with:
 * - `seconds`: The timeout duration in seconds
 * - `enforced`: Whether timeout was enforced (true if PCNTL available)
 *
 * Example:
 * $result = ProcessFile::run($filePath);
 * // $result->_timeout = [
 * //     'seconds' => 300,
 * //     'enforced' => true,
 * // ];
 */
class TimeoutDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with timeout enforcement.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \RuntimeException If execution exceeds timeout
     */
    public function handle(...$arguments)
    {
        // Check if timeout is enabled
        if (! $this->isTimeoutEnabled()) {
            return $this->action->handle(...$arguments);
        }

        $timeout = $this->getTimeout();

        // Use PCNTL if available for precise timeout enforcement
        if (function_exists('pcntl_alarm') && extension_loaded('pcntl')) {
            return $this->handleWithPcntl($timeout, $arguments);
        }

        // Fallback to timer-based timeout
        return $this->handleWithTimer($timeout, $arguments);
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
     * Execute with PCNTL alarm for precise timeout enforcement.
     *
     * @param  int  $timeout  Timeout in seconds
     * @param  array  $arguments  Action arguments
     *
     * @throws \RuntimeException If execution exceeds timeout
     */
    protected function handleWithPcntl(int $timeout, array $arguments): mixed
    {
        $handler = function () {
            throw new \RuntimeException('Action execution timeout exceeded');
        };

        pcntl_signal(SIGALRM, $handler);
        pcntl_alarm($timeout);

        try {
            $result = $this->action->handle(...$arguments);
            pcntl_alarm(0); // Cancel alarm

            // Add timeout metadata to result
            return $this->addTimeoutMetadata($result, $timeout, true);
        } catch (\Throwable $e) {
            pcntl_alarm(0); // Cancel alarm

            throw $e;
        }
    }

    /**
     * Execute with timer-based timeout (fallback when PCNTL unavailable).
     *
     * @param  int  $timeout  Timeout in seconds
     * @param  array  $arguments  Action arguments
     *
     * @throws \RuntimeException If execution exceeds timeout
     */
    protected function handleWithTimer(int $timeout, array $arguments): mixed
    {
        $startTime = microtime(true);
        $maxEndTime = $startTime + $timeout;

        $result = $this->action->handle(...$arguments);

        $elapsed = microtime(true) - $startTime;

        if ($elapsed > $timeout || microtime(true) > $maxEndTime) {
            throw new \RuntimeException("Action execution timeout exceeded ({$timeout}s)");
        }

        // Add timeout metadata to result
        return $this->addTimeoutMetadata($result, $timeout, false);
    }

    /**
     * Determine if timeout is enabled.
     *
     * Checks for:
     * 1. #[TimeoutEnabled] attribute on the action
     * 2. isTimeoutEnabled() method on the action
     * 3. timeoutEnabled property on the action
     * 4. Defaults to true
     */
    protected function isTimeoutEnabled(): bool
    {
        // Check for attribute first
        $enabled = $this->getAttributeValue(TimeoutEnabled::class);
        if ($enabled !== null) {
            return (bool) $enabled;
        }

        // Fall back to method or property
        $fromAction = $this->fromActionMethodOrProperty(
            'isTimeoutEnabled',
            'timeoutEnabled',
            null
        );

        if ($fromAction !== null) {
            return (bool) $fromAction;
        }

        return true; // Default to enabled
    }

    /**
     * Get the timeout duration in seconds.
     *
     * Checks for:
     * 1. #[TimeoutSeconds] attribute on the action
     * 2. getTimeout() method on the action
     * 3. timeout property on the action
     * 4. Defaults to 300 seconds (5 minutes)
     *
     * @return int Timeout in seconds
     */
    protected function getTimeout(): int
    {
        // Check for attribute first
        $timeout = $this->getAttributeValue(TimeoutSeconds::class);
        if ($timeout !== null) {
            return $timeout;
        }

        // Fall back to method or property
        return $this->fromActionMethodOrProperty(
            'getTimeout',
            'timeout',
            300
        );
    }

    /**
     * Get attribute value from the original action.
     *
     * @param  string  $attributeClass  The attribute class name
     * @return mixed The attribute value, or null if not found
     */
    protected function getAttributeValue(string $attributeClass): mixed
    {
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof TimeoutSeconds) {
                    return $attribute->seconds;
                }
                if ($attribute instanceof TimeoutEnabled) {
                    return $attribute->enabled;
                }
            }
        } catch (\ReflectionException $e) {
            // Attribute not found or can't be read
        }

        return null;
    }

    /**
     * Get the original action (unwrap decorators).
     *
     * @return mixed
     */
    protected function getOriginalAction()
    {
        $action = $this->action;

        // Unwrap decorators to get the original action
        while (str_starts_with(get_class($action), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($action);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $action = $property->getValue($action);
            } else {
                break;
            }
        }

        return $action;
    }

    /**
     * Add timeout metadata to the result.
     *
     * Adds a `_timeout` property to the result indicating:
     * - Timeout duration in seconds
     * - Whether timeout was enforced (PCNTL vs timer-based)
     *
     * @param  mixed  $result  The action result
     * @param  int  $timeout  The timeout duration in seconds
     * @param  bool  $enforced  Whether timeout was enforced (PCNTL)
     * @return mixed The result with timeout metadata added
     */
    protected function addTimeoutMetadata(mixed $result, int $timeout, bool $enforced): mixed
    {
        $metadata = [
            'seconds' => $timeout,
            'enforced' => $enforced,
        ];

        if (is_array($result)) {
            $result['_timeout'] = $metadata;

            return $result;
        }

        if (is_object($result)) {
            // Try to add timeout metadata as property (preserves object type)
            try {
                $reflection = new \ReflectionClass($result);
                if ($reflection->hasProperty('_timeout')) {
                    $property = $reflection->getProperty('_timeout');
                    $property->setAccessible(true);
                    $property->setValue($result, $metadata);
                } else {
                    // Property doesn't exist, use dynamic property (works for Eloquent models)
                    $result->_timeout = $metadata;
                }
            } catch (\ReflectionException $e) {
                // Fallback: try direct assignment
                $result->_timeout = $metadata;
            }

            return $result;
        }

        // For other types, wrap in array with metadata
        return [
            'data' => $result,
            '_timeout' => $metadata,
        ];
    }
}
