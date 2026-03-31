<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Automatically tracks action execution for testing purposes.
 *
 * Uses the decorator pattern to automatically wrap actions and track
 * execution history. The TestableDecorator intercepts handle() calls
 * and records call information for testing and debugging.
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
 * Benefits:
 * - Automatic call history tracking
 * - Argument and result recording
 * - Execution timing information
 * - Test metadata in results
 * - Enable/disable per action
 * - Seamless integration with other decorators
 *
 * Testing Utilities:
 * Use these static methods in your tests to access call history:
 * - getCallHistory(): Get all recorded calls
 * - clearCallHistory(): Clear recorded call history
 * - assertCalled(): Assert action was called (to be implemented)
 * - assertNotCalled(): Assert action was not called (to be implemented)
 * - assertCalledTimes(): Assert action was called N times (to be implemented)
 *
 * @example
 * // Basic usage - tracking happens automatically:
 * class ProcessOrder
 * {
 *     use AsAction;
 *     use AsTestable;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Action logic - automatically tracked
 *     }
 * }
 *
 * // Usage:
 * $result = ProcessOrder::run($order);
 * // $result->_testable = [
 * //     'call_id' => 'abc123...',
 * //     'call_number' => 1,
 * //     'execution_time' => 45.2,
 * //     'enabled' => true,
 * // ];
 *
 * // In tests:
 * $history = ProcessOrder::getCallHistory();
 * // Returns array of all calls with arguments, results, timing, etc.
 * @example
 * // Enable/disable via attribute:
 * use App\Actions\Attributes\TestableEnabled;
 *
 * #[TestableEnabled(true)]  // Enable tracking
 * class ProcessOrder
 * {
 *     use AsAction;
 *     use AsTestable;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Action logic
 *     }
 * }
 * @example
 * // Disable tracking via attribute:
 * use App\Actions\Attributes\TestableEnabled;
 *
 * #[TestableEnabled(false)] // Disable tracking for this action
 * class ProcessOrder
 * {
 *     use AsAction;
 *     use AsTestable;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Action logic - not tracked
 *     }
 * }
 */
trait AsTestable
{
    /**
     * Get call history for testing.
     *
     * Returns array of all calls made to this action, including:
     * - call_id: Unique identifier for each call
     * - call_number: Sequential call number
     * - arguments: Serialized arguments
     * - result_type: Type of result
     * - execution_time: Execution time in milliseconds
     * - timestamp: When the call was made
     * - success: Whether the call succeeded
     * - exception: Exception class if call failed
     */
    public static function getCallHistory(): array
    {
        $key = 'testable:history:'.static::class;

        return Cache::get($key, []);
    }

    /**
     * Clear call history.
     *
     * Removes all recorded call history for this action.
     */
    public static function clearCallHistory(): void
    {
        $key = 'testable:history:'.static::class;
        Cache::forget($key);

        $callNumberKey = 'testable:call_number:'.static::class;
        Cache::forget($callNumberKey);
    }

    /**
     * Assert action was called with specific arguments.
     *
     * @param  array|null  $arguments  Expected arguments (optional)
     */
    public static function assertCalled(?array $arguments = null): void
    {
        $history = static::getCallHistory();

        if (empty($history)) {
            throw new \RuntimeException('Action was not called.');
        }

        if ($arguments !== null) {
            // Check if any call matches the arguments
            $found = false;
            foreach ($history as $call) {
                if ($call['arguments'] === $arguments) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                throw new \RuntimeException('Action was not called with expected arguments.');
            }
        }
    }

    /**
     * Assert action was not called.
     *
     * @throws \RuntimeException If assertion fails
     */
    public static function assertNotCalled(): void
    {
        $history = static::getCallHistory();

        if (! empty($history)) {
            throw new \RuntimeException('Action was called but should not have been.');
        }
    }

    /**
     * Assert action was called a specific number of times.
     *
     * @param  int  $times  Expected number of calls
     *
     * @throws \RuntimeException If assertion fails
     */
    public static function assertCalledTimes(int $times): void
    {
        $history = static::getCallHistory();
        $actualTimes = count($history);

        if ($actualTimes !== $times) {
            throw new \RuntimeException(
                "Expected action to be called {$times} times, but it was called {$actualTimes} times."
            );
        }
    }
}
