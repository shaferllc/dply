<?php

declare(strict_types=1);

namespace App\Actions\Helpers;

use App\Actions\Actions;
use App\Actions\Concerns\AsFake;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Action Testing Utilities - Helper methods for testing actions.
 *
 * Provides convenient methods for testing actions, including
 * faking, mocking, and assertion helpers.
 *
 * @example
 * // Fake an action for testing
 * use App\Actions\Concerns\AsFake;
 *
 * class ProcessOrder extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(Order $order): Order
 *     {
 *         return $order;
 *     }
 * }
 *
 * // In tests:
 * $mockOrder = new Order();
 * ActionTest::fake(ProcessOrder::class, $mockOrder);
 *
 * // Now when ProcessOrder::run() is called, it returns $mockOrder
 * $result = ProcessOrder::run($order); // Returns $mockOrder
 * @example
 * // Assert action was called
 * ActionTest::assertActionCalled(ProcessOrder::class, 1);
 * ActionTest::assertActionCalled(ProcessOrder::class, 3); // Called exactly 3 times
 * @example
 * // Assert action was called with specific arguments
 * ActionTest::assertActionCalledWith(ProcessOrder::class, [$order]);
 * @example
 * // Assert action was not called
 * ActionTest::assertActionNotCalled(ProcessOrder::class);
 * @example
 * // Assert action has decorator
 * ActionTest::assertActionDecorated(ProcessOrder::class, AsAuthenticated::class);
 * @example
 * // Mock an action
 * $mock = ActionTest::mockAction(ProcessOrder::class);
 * $mock->shouldReceive('handle')
 *     ->once()
 *     ->with(\Mockery::type(Order::class))
 *     ->andReturn($processedOrder);
 * @example
 * // Spy on an action (records calls without affecting behavior)
 * $spy = ActionTest::spyAction(ProcessOrder::class);
 * ProcessOrder::run($order);
 * $spy->shouldHaveReceived('handle')->once();
 */
class ActionTest
{
    /**
     * Fake an action for testing.
     *
     * @param  string  $actionClass  Action class name
     * @param  mixed  $returnValue  Value to return when action is called
     */
    public static function fake(string $actionClass, mixed $returnValue = null): MockInterface
    {
        if (! in_array(AsFake::class, class_uses_recursive($actionClass))) {
            throw new \InvalidArgumentException("Action {$actionClass} must use AsFake trait to be faked.");
        }

        return $actionClass::fake($returnValue);
    }

    /**
     * Assert that an action was called.
     *
     * @param  string  $actionClass  Action class name
     * @param  int  $times  Expected number of calls
     */
    public static function assertActionCalled(string $actionClass, int $times = 1): void
    {
        if (! in_array(AsFake::class, class_uses_recursive($actionClass))) {
            throw new \InvalidArgumentException("Action {$actionClass} must use AsFake trait to assert calls.");
        }

        $actionClass::shouldHaveReceived('handle')->times($times);
    }

    /**
     * Assert that an action has a specific decorator.
     *
     * @param  string  $actionClass  Action class name
     * @param  string  $trait  Trait class name
     */
    public static function assertActionDecorated(string $actionClass, string $trait): void
    {
        $traits = class_uses_recursive($actionClass);

        if (! in_array($trait, $traits)) {
            throw new AssertionFailedError(
                "Action {$actionClass} does not use trait {$trait}"
            );
        }
    }

    /**
     * Mock an action.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function mockAction(string $actionClass): MockInterface
    {
        return Mockery::mock($actionClass);
    }

    /**
     * Create a spy for an action (records calls without affecting behavior).
     *
     * @param  string  $actionClass  Action class name
     */
    public static function spyAction(string $actionClass): MockInterface
    {
        return Mockery::spy($actionClass);
    }

    /**
     * Assert that an action was called with specific arguments.
     *
     * @param  string  $actionClass  Action class name
     * @param  array  $arguments  Expected arguments
     */
    public static function assertActionCalledWith(string $actionClass, array $arguments): void
    {
        if (! in_array(AsFake::class, class_uses_recursive($actionClass))) {
            throw new \InvalidArgumentException("Action {$actionClass} must use AsFake trait to assert calls.");
        }

        $actionClass::shouldHaveReceived('handle')->with(...$arguments);
    }

    /**
     * Assert that an action was not called.
     *
     * @param  string  $actionClass  Action class name
     */
    public static function assertActionNotCalled(string $actionClass): void
    {
        if (! in_array(AsFake::class, class_uses_recursive($actionClass))) {
            throw new \InvalidArgumentException("Action {$actionClass} must use AsFake trait to assert calls.");
        }

        $actionClass::shouldNotHaveReceived('handle');
    }
}
