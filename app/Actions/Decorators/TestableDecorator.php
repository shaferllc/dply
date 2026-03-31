<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\TestableEnabled;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Cache;

/**
 * Testable Decorator
 *
 * Automatically tracks action execution for testing purposes.
 * This decorator records call history, arguments, results, and timing
 * information to make actions easier to test and debug.
 *
 * Features:
 * - Automatic call history tracking
 * - Argument and result recording
 * - Execution timing
 * - Test metadata in results
 * - Enable/disable per action
 * - Seamless integration with other decorators
 *
 * How it works:
 * 1. When an action uses AsTestable, TestableDesignPattern recognizes it
 * 2. ActionManager wraps the action with TestableDecorator
 * 3. When handle() is called, the decorator:
 *    - Records call start time
 *    - Executes the action
 *    - Records call end time and result
 *    - Stores call history in cache
 *    - Adds test metadata to the result
 *
 * Test Metadata:
 * The result will include a `_testable` property with:
 * - `call_id`: Unique identifier for this call
 * - `call_number`: Sequential call number for this action
 * - `execution_time`: Execution time in milliseconds
 * - `enabled`: Whether testability tracking was enabled
 *
 * Example:
 * $result = CreateTag::run($team, ['name' => 'New Tag']);
 * // $result->_testable = [
 * //     'call_id' => 'abc123...',
 * //     'call_number' => 1,
 * //     'execution_time' => 45.2,
 * //     'enabled' => true,
 * // ];
 */
class TestableDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with testability tracking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        // Check if testability is enabled
        if (! $this->isTestableEnabled()) {
            return $this->action->handle(...$arguments);
        }

        $startTime = microtime(true);
        $callId = $this->generateCallId();
        $callNumber = $this->getNextCallNumber();

        try {
            $result = $this->action->handle(...$arguments);

            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

            // Record call history
            $this->recordCall($callId, $callNumber, $arguments, $result, $executionTime);

            // Add test metadata to result
            return $this->addTestableMetadata($result, $callId, $callNumber, $executionTime, true);
        } catch (\Throwable $e) {
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;

            // Record failed call
            $this->recordCall($callId, $callNumber, $arguments, null, $executionTime, $e);

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
     * Determine if testability tracking is enabled.
     *
     * Checks for:
     * 1. #[TestableEnabled] attribute on the action
     * 2. isTestableEnabled() method on the action
     * 3. testableEnabled property on the action
     * 4. Defaults to true
     */
    protected function isTestableEnabled(): bool
    {
        // Check for attribute first
        $enabled = $this->getAttributeValue(TestableEnabled::class);
        if ($enabled !== null) {
            return (bool) $enabled;
        }

        // Fall back to method or property
        $fromAction = $this->fromActionMethodOrProperty(
            'isTestableEnabled',
            'testableEnabled',
            null
        );

        if ($fromAction !== null) {
            return (bool) $fromAction;
        }

        return true; // Default to enabled
    }

    /**
     * Generate a unique call ID.
     */
    protected function generateCallId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get the next call number for this action.
     */
    protected function getNextCallNumber(): int
    {
        $originalAction = $this->getOriginalAction();
        $key = 'testable:call_number:'.get_class($originalAction);

        return (int) Cache::increment($key, 1);
    }

    /**
     * Record a call in the call history.
     *
     * @param  string  $callId  Unique call identifier
     * @param  int  $callNumber  Sequential call number
     * @param  array  $arguments  Action arguments
     * @param  mixed  $result  Action result
     * @param  float  $executionTime  Execution time in milliseconds
     * @param  \Throwable|null  $exception  Exception if call failed
     */
    protected function recordCall(string $callId, int $callNumber, array $arguments, mixed $result, float $executionTime, ?\Throwable $exception = null): void
    {
        $originalAction = $this->getOriginalAction();
        $historyKey = 'testable:history:'.get_class($originalAction);

        $call = [
            'call_id' => $callId,
            'call_number' => $callNumber,
            'arguments' => $this->serializeArguments($arguments),
            'result_type' => $this->getResultType($result),
            'execution_time' => $executionTime,
            'timestamp' => now()->toIso8601String(),
            'success' => $exception === null,
            'exception' => $exception ? get_class($exception) : null,
        ];

        // Get existing history
        $history = Cache::get($historyKey, []);
        $history[] = $call;

        // Keep only last 100 calls
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }

        // Store history (TTL: 1 hour)
        Cache::put($historyKey, $history, 3600);
    }

    /**
     * Serialize arguments for storage.
     */
    protected function serializeArguments(array $arguments): array
    {
        return array_map(function ($arg) {
            if (is_object($arg)) {
                return [
                    'type' => get_class($arg),
                    'id' => method_exists($arg, 'getKey') ? $arg->getKey() : null,
                    'string' => method_exists($arg, '__toString') ? (string) $arg : null,
                ];
            }

            return $arg;
        }, $arguments);
    }

    /**
     * Get the type of the result.
     */
    protected function getResultType(mixed $result): string
    {
        if (is_object($result)) {
            return get_class($result);
        }

        return gettype($result);
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
                if ($attribute instanceof TestableEnabled) {
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
     * Add testable metadata to the result.
     *
     * Adds a `_testable` property to the result indicating:
     * - Call ID
     * - Call number
     * - Execution time
     * - Whether testability was enabled
     *
     * @param  mixed  $result  The action result
     * @param  string  $callId  Unique call identifier
     * @param  int  $callNumber  Sequential call number
     * @param  float  $executionTime  Execution time in milliseconds
     * @param  bool  $enabled  Whether testability was enabled
     * @return mixed The result with testable metadata added
     */
    protected function addTestableMetadata(mixed $result, string $callId, int $callNumber, float $executionTime, bool $enabled): mixed
    {
        $metadata = [
            'call_id' => $callId,
            'call_number' => $callNumber,
            'execution_time' => round($executionTime, 2),
            'enabled' => $enabled,
        ];

        if (is_array($result)) {
            $result['_testable'] = $metadata;

            return $result;
        }

        if (is_object($result)) {
            // Try to add testable metadata as property (preserves object type)
            try {
                $reflection = new \ReflectionClass($result);
                if ($reflection->hasProperty('_testable')) {
                    $property = $reflection->getProperty('_testable');
                    $property->setAccessible(true);
                    $property->setValue($result, $metadata);
                } else {
                    // Property doesn't exist, use dynamic property (works for Eloquent models)
                    $result->_testable = $metadata;
                }
            } catch (\ReflectionException $e) {
                // Fallback: try direct assignment
                $result->_testable = $metadata;
            }

            return $result;
        }

        // For other types, wrap in array with metadata
        return [
            'data' => $result,
            '_testable' => $metadata,
        ];
    }
}
