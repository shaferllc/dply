<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\ThrottleEnabled;
use App\Actions\Attributes\ThrottleMaxConcurrent;
use App\Actions\Attributes\ThrottleTtl;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Throttle Decorator
 *
 * Automatically throttles action execution to limit concurrent runs.
 * This decorator prevents too many instances of an action from running
 * simultaneously, which is useful for resource-intensive operations.
 *
 * Features:
 * - Automatic concurrent execution limiting
 * - Configurable max concurrent executions
 * - Configurable TTL for throttle keys
 * - Custom throttle key generation
 * - Throttle metadata in results
 * - Enable/disable per action
 * - Seamless integration with other decorators
 *
 * How it works:
 * 1. When an action uses AsThrottle, ThrottleDesignPattern recognizes it
 * 2. ActionManager wraps the action with ThrottleDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates a throttle key (based on action and arguments)
 *    - Checks current concurrent executions
 *    - Throws exception if max concurrent reached
 *    - Increments counter, executes action, then decrements
 *    - Adds throttle metadata to the result
 *
 * Throttle Metadata:
 * The result will include a `_throttle` property with:
 * - `max_concurrent`: Maximum allowed concurrent executions
 * - `current`: Current concurrent executions (at time of execution start)
 * - `enabled`: Whether throttling was enabled
 *
 * Example:
 * $result = ProcessFile::run($filePath);
 * // $result->_throttle = [
 * //     'max_concurrent' => 5,
 * //     'current' => 2,
 * //     'enabled' => true,
 * // ];
 */
class ThrottleDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with throttling.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws \RuntimeException If max concurrent executions reached
     */
    public function handle(...$arguments)
    {
        // Check if throttling is enabled
        if (! $this->isThrottleEnabled()) {
            return $this->action->handle(...$arguments);
        }

        $key = $this->getThrottleKey($arguments);
        $maxConcurrent = $this->getMaxConcurrent();
        $ttl = $this->getThrottleTtl();

        $current = (int) Cache::get($key, 0);

        if ($current >= $maxConcurrent) {
            throw new \RuntimeException('Maximum concurrent executions reached. Please try again later.');
        }

        // Increment counter
        Cache::increment($key, 1);
        Cache::put($key, Cache::get($key), $ttl);

        try {
            $result = $this->action->handle(...$arguments);

            // Add throttle metadata to result
            return $this->addThrottleMetadata($result, $maxConcurrent, $current, true);
        } finally {
            // Decrement counter when done
            $currentCount = (int) Cache::get($key, 0);
            if ($currentCount > 0) {
                Cache::decrement($key, 1);
            }
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
     * Determine if throttling is enabled.
     *
     * Checks for:
     * 1. #[ThrottleEnabled] attribute on the action
     * 2. isThrottleEnabled() method on the action
     * 3. throttleEnabled property on the action
     * 4. Defaults to true
     */
    protected function isThrottleEnabled(): bool
    {
        // Check for attribute first
        $enabled = $this->getAttributeValue(ThrottleEnabled::class);
        if ($enabled !== null) {
            return (bool) $enabled;
        }

        // Fall back to method or property
        $fromAction = $this->fromActionMethodOrProperty(
            'isThrottleEnabled',
            'throttleEnabled',
            null
        );

        if ($fromAction !== null) {
            return (bool) $fromAction;
        }

        return true; // Default to enabled
    }

    /**
     * Get the throttle key for tracking concurrent executions.
     *
     * Checks for:
     * 1. buildThrottleKey() method on the action (receives arguments)
     * 2. Defaults to 'throttle:{action_class}'
     *
     * @param  array  $arguments  Action arguments
     */
    protected function getThrottleKey(array $arguments): string
    {
        if ($this->hasMethod('buildThrottleKey')) {
            return $this->callMethod('buildThrottleKey', $arguments);
        }

        // Get the original action class name
        $originalAction = $this->getOriginalAction();

        return 'throttle:'.get_class($originalAction);
    }

    /**
     * Get the maximum concurrent executions allowed.
     *
     * Checks for:
     * 1. #[ThrottleMaxConcurrent] attribute on the action
     * 2. getMaxConcurrent() method on the action
     * 3. maxConcurrent property on the action
     * 4. Defaults to 5
     */
    protected function getMaxConcurrent(): int
    {
        // Check for attribute first
        $max = $this->getAttributeValue(ThrottleMaxConcurrent::class);
        if ($max !== null) {
            return $max;
        }

        // Fall back to method or property
        return $this->fromActionMethodOrProperty(
            'getMaxConcurrent',
            'maxConcurrent',
            5
        );
    }

    /**
     * Get the TTL for throttle keys in seconds.
     *
     * Checks for:
     * 1. #[ThrottleTtl] attribute on the action
     * 2. getThrottleTtl() method on the action
     * 3. throttleTtl property on the action
     * 4. Defaults to 300 seconds (5 minutes)
     */
    protected function getThrottleTtl(): int
    {
        // Check for attribute first
        $ttl = $this->getAttributeValue(ThrottleTtl::class);
        if ($ttl !== null) {
            return $ttl;
        }

        // Fall back to method or property
        return $this->fromActionMethodOrProperty(
            'getThrottleTtl',
            'throttleTtl',
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
                if ($attribute instanceof ThrottleMaxConcurrent) {
                    return $attribute->max;
                }
                if ($attribute instanceof ThrottleTtl) {
                    return $attribute->seconds;
                }
                if ($attribute instanceof ThrottleEnabled) {
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
     * Add throttle metadata to the result.
     *
     * Adds a `_throttle` property to the result indicating:
     * - Maximum concurrent executions
     * - Current concurrent executions (at start)
     * - Whether throttling was enabled
     *
     * @param  mixed  $result  The action result
     * @param  int  $maxConcurrent  Maximum concurrent executions
     * @param  int  $current  Current concurrent executions
     * @param  bool  $enabled  Whether throttling was enabled
     * @return mixed The result with throttle metadata added
     */
    protected function addThrottleMetadata(mixed $result, int $maxConcurrent, int $current, bool $enabled): mixed
    {
        $metadata = [
            'max_concurrent' => $maxConcurrent,
            'current' => $current,
            'enabled' => $enabled,
        ];

        if (is_array($result)) {
            $result['_throttle'] = $metadata;

            return $result;
        }

        if (is_object($result)) {
            // Try to add throttle metadata as property (preserves object type)
            try {
                $reflection = new \ReflectionClass($result);
                if ($reflection->hasProperty('_throttle')) {
                    $property = $reflection->getProperty('_throttle');
                    $property->setAccessible(true);
                    $property->setValue($result, $metadata);
                } else {
                    // Property doesn't exist, use dynamic property (works for Eloquent models)
                    $result->_throttle = $metadata;
                }
            } catch (\ReflectionException $e) {
                // Fallback: try direct assignment
                $result->_throttle = $metadata;
            }

            return $result;
        }

        // For other types, wrap in array with metadata
        return [
            'data' => $result,
            '_throttle' => $metadata,
        ];
    }
}
