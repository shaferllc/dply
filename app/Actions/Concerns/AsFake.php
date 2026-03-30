<?php

namespace App\Actions\Concerns;

use Mockery;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\HigherOrderMessage;
use Mockery\MockInterface;

/**
 * Provides mocking and faking capabilities for actions in tests.
 *
 * This trait enables easy mocking, spying, and partial mocking of actions
 * using Mockery. It integrates with Laravel's service container to replace
 * real action instances with fakes during testing.
 *
 * Features:
 * - Full action mocking with Mockery
 * - Action spying (track calls without affecting behavior)
 * - Partial mocking (mock some methods, use real implementation for others)
 * - Container integration (fakes are resolved from container)
 * - Expectation helpers (shouldRun, shouldNotRun, allowToRun)
 * - Fake instance management (isFake, clearFake)
 *
 * Benefits:
 * - Easy action mocking in tests
 * - Isolate dependencies
 * - Verify action calls
 * - Control action behavior in tests
 * - Works with Laravel's service container
 * - Compatible with Pest and PHPUnit
 *
 * Note: This trait does NOT use the decorator pattern. It's a testing utility
 * that provides mocking capabilities. The ActionManager recognizes when actions
 * are faked and returns the fake instance instead of the real one.
 *
 * Methods:
 * - `mock()` - Create a full mock of the action
 * - `spy()` - Create a spy (tracks calls, uses real implementation)
 * - `partialMock()` - Create a partial mock
 * - `shouldRun()` - Expect handle() to be called
 * - `shouldNotRun()` - Expect handle() NOT to be called
 * - `allowToRun()` - Allow handle() to be called (spy)
 * - `isFake()` - Check if action is currently faked
 * - `clearFake()` - Remove fake instance from container
 *
 * Additional Mockery Methods Available:
 *
 * All methods return Mockery interfaces, so you can chain any Mockery method.
 * Here are the most commonly used additional methods:
 *
 * Call Count Expectations:
 * - `once()` - Expect exactly one call
 * - `twice()` - Expect exactly two calls
 * - `times(int $count)` - Expect exactly N calls
 * - `never()` - Expect never to be called
 * - `atLeast()->times(int $count)` - Expect at least N calls
 * - `atMost()->times(int $count)` - Expect at most N calls
 * - `between(int $min, int $max)` - Expect between min and max calls
 * - `zeroOrMoreTimes()` - Allow zero or more calls
 *
 * Argument Matching:
 * - `with(...$args)` - Match specific arguments
 * - `withNoArgs()` - Match no arguments
 * - `withAnyArgs()` - Match any arguments
 * - `withArgs(callable $matcher)` - Match using a closure
 * - `with(\Mockery::type($type))` - Match by type
 * - `with(\Mockery::on(callable $matcher))` - Match using closure
 * - `with(\Mockery::pattern($pattern))` - Match by regex pattern
 * - `with(\Mockery::ducktype($name))` - Match by duck typing
 * - `with(\Mockery::subset($array))` - Match array subset
 * - `with(\Mockery::contains($value))` - Match array containing value
 * - `with(\Mockery::hasKey($key))` - Match array with key
 * - `with(\Mockery::hasValue($value))` - Match array with value
 *
 * Return Values:
 * - `andReturn($value, ...)` - Return value(s) in order
 * - `andReturnNull()` - Return null
 * - `andReturnSelf()` - Return the mock itself
 * - `andReturnUndefined()` - Return undefined
 * - `andReturnUsing(callable $callback)` - Return using callback
 * - `andReturnValues(array $values)` - Return array of values
 *
 * Exceptions:
 * - `andThrow(\Throwable $exception)` - Throw exception
 * - `andThrow($exceptionClass, $message, $code, $previous)` - Throw exception
 * - `andThrowExceptions(array $exceptions)` - Throw exceptions in order
 *
 * Behavior:
 * - `andSet($property, $value)` - Set a property
 * - `andSet($property, $value, $value2, ...)` - Set property with multiple values
 * - `passthru()` - Pass through to real method (partial mocks)
 *
 * Ordering:
 * - `ordered()` - Expect calls in order
 * - `globally()` - Expect calls globally ordered
 *
 * Mock Configuration:
 * - `makePartial()` - Make a partial mock
 * - `shouldAllowMockingProtectedMethods()` - Allow mocking protected methods
 * - `shouldIgnoreMissing()` - Ignore missing method calls
 * - `shouldAllowMockingNonExistentMethods()` - Allow mocking non-existent methods
 * - `byDefault()` - Set as default expectation
 * - `getMock()` - Get the underlying mock
 *
 * Spy Verification (after calling spy()):
 * - `shouldHaveReceived($method)` - Verify method was called
 * - `shouldHaveReceived($method)->once()` - Verify called once
 * - `shouldHaveReceived($method)->times($count)` - Verify called N times
 * - `shouldHaveReceived($method)->with(...$args)` - Verify with args
 * - `shouldNotHaveReceived($method)` - Verify never called
 * - `shouldNotHaveReceived($method)->with(...$args)` - Verify never called with args
 *
 * Higher Order Messages:
 * - `shouldReceive()->get()->andReturn($value)` - Chain method calls
 * - `shouldReceive()->get()->once()->andReturn($value)` - Chain with expectations
 *
 * Usage Examples:
 * ```php
 * // Call count
 * Action::shouldRun()->twice();
 * Action::shouldRun()->atLeast()->times(3);
 * Action::shouldRun()->between(2, 5);
 *
 * // Arguments
 * Action::shouldRun()->withNoArgs();
 * Action::shouldRun()->with(\Mockery::type(User::class));
 * Action::shouldRun()->with(\Mockery::on(fn($v) => $v > 10));
 *
 * // Returns
 * Action::shouldRun()->andReturnSelf();
 * Action::shouldRun()->andReturnUsing(fn($arg) => $arg * 2);
 *
 * // Exceptions
 * Action::shouldRun()->andThrow(new \Exception('Error'));
 *
 * // Spy verification
 * $spy = Action::spy();
 * Action::run();
 * $spy->shouldHaveReceived('handle')->once();
 * ```
 *
 * @example
 * // ============================================
 * // Example 1: Basic Mocking
 * // ============================================
 * class SendEmailAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(string $email, string $message): void
 *     {
 *         Mail::to($email)->send(new NotificationMail($message));
 *     }
 * }
 *
 * // In tests:
 * SendEmailAction::mock()
 *     ->shouldReceive('handle')
 *     ->once()
 *     ->with('user@example.com', 'Hello')
 *     ->andReturnNull();
 *
 * // Action is now mocked - calls return null
 * SendEmailAction::run('user@example.com', 'Hello');
 * @example
 * // ============================================
 * // Example 2: Using shouldRun() Helper
 * // ============================================
 * class ProcessOrderAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * // In tests:
 * SendEmailAction::shouldRun()
 *     ->once()
 *     ->with(\Mockery::type(Order::class))
 *     ->andReturnNull();
 *
 * ProcessOrderAction::run($order);
 * @example
 * // ============================================
 * // Example 3: Using shouldNotRun()
 * // ============================================
 * class ConditionalAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(bool $condition): void
 *     {
 *         if ($condition) {
 *             // Do something
 *         }
 *     }
 * }
 *
 * // In tests:
 * ConditionalAction::shouldNotRun();
 *
 * ConditionalAction::run(false); // Should not be called
 * @example
 * // ============================================
 * // Example 4: Using spy()
 * // ============================================
 * class TrackedAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): string
 *     {
 *         return 'result';
 *     }
 * }
 *
 * // In tests:
 * $spy = TrackedAction::spy();
 *
 * $result = TrackedAction::run();
 *
 * // Verify it was called
 * $spy->shouldHaveReceived('handle')->once();
 * expect($result)->toBe('result'); // Real implementation still runs
 * @example
 * // ============================================
 * // Example 5: Partial Mock
 * // ============================================
 * class ComplexAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): string
 *     {
 *         return $this->getData();
 *     }
 *
 *     protected function getData(): string
 *     {
 *         return 'real data';
 *     }
 * }
 *
 * // In tests:
 * $mock = ComplexAction::partialMock();
 * $mock->shouldReceive('getData')->andReturn('mocked data');
 *
 * $result = ComplexAction::run();
 * expect($result)->toBe('mocked data');
 * @example
 * // ============================================
 * // Example 6: Mock with Return Value
 * // ============================================
 * class GetUserAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(int $id): User
 *     {
 *         return User::find($id);
 *     }
 * }
 *
 * // In tests:
 * $user = User::factory()->make();
 *
 * GetUserAction::mock()
 *     ->shouldReceive('handle')
 *     ->with(1)
 *     ->andReturn($user);
 *
 * $result = GetUserAction::run(1);
 * expect($result)->toBe($user);
 * @example
 * // ============================================
 * // Example 7: Mock with Multiple Calls
 * // ============================================
 * class CounterAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): int
 *     {
 *         return 1;
 *     }
 * }
 *
 * // In tests:
 * CounterAction::mock()
 *     ->shouldReceive('handle')
 *     ->times(3)
 *     ->andReturn(1, 2, 3);
 *
 * expect(CounterAction::run())->toBe(1);
 * expect(CounterAction::run())->toBe(2);
 * expect(CounterAction::run())->toBe(3);
 * @example
 * // ============================================
 * // Example 8: Mock with Exceptions
 * // ============================================
 * class FailingAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * FailingAction::mock()
 *     ->shouldReceive('handle')
 *     ->andThrow(new \Exception('Action failed'));
 *
 * expect(fn() => FailingAction::run())->toThrow(\Exception::class);
 * @example
 * // ============================================
 * // Example 9: Mock with Arguments
 * // ============================================
 * class ProcessDataAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(array $data): array
 *     {
 *         return $data;
 *     }
 * }
 *
 * // In tests:
 * ProcessDataAction::mock()
 *     ->shouldReceive('handle')
 *     ->with(['key' => 'value'])
 *     ->andReturn(['processed' => true]);
 *
 * $result = ProcessDataAction::run(['key' => 'value']);
 * expect($result)->toBe(['processed' => true]);
 * @example
 * // ============================================
 * // Example 10: Using allowToRun() (Spy)
 * // ============================================
 * class SpiedAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): string
 *     {
 *         return 'real result';
 *     }
 * }
 *
 * // In tests:
 * SpiedAction::allowToRun();
 *
 * $result = SpiedAction::run();
 * expect($result)->toBe('real result'); // Real implementation
 *
 * // Verify it was called
 * SpiedAction::spy()->shouldHaveReceived('handle')->once();
 * @example
 * // ============================================
 * // Example 11: Checking if Fake
 * // ============================================
 * class CheckableAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * expect(CheckableAction::isFake())->toBeFalse();
 *
 * CheckableAction::mock();
 *
 * expect(CheckableAction::isFake())->toBeTrue();
 * @example
 * // ============================================
 * // Example 12: Clearing Fake
 * // ============================================
 * class ClearableAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): string
 *     {
 *         return 'real';
 *     }
 * }
 *
 * // In tests:
 * ClearableAction::mock()->shouldReceive('handle')->andReturn('fake');
 * expect(ClearableAction::run())->toBe('fake');
 *
 * ClearableAction::clearFake();
 *
 * expect(ClearableAction::run())->toBe('real'); // Back to real implementation
 * @example
 * // ============================================
 * // Example 13: Mock in Pest Tests
 * // ============================================
 * class TestableAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In Pest test:
 * test('action is mocked', function () {
 *     TestableAction::shouldRun()->once();
 *
 *     TestableAction::run();
 * });
 * @example
 * // ============================================
 * // Example 14: Mock in PHPUnit Tests
 * // ============================================
 * class UnitTestAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In PHPUnit test:
 * public function test_action_is_mocked(): void
 * {
 *     UnitTestAction::shouldRun()->once();
 *
 *     UnitTestAction::run();
 * }
 * @example
 * // ============================================
 * // Example 15: Mock with Callback
 * // ============================================
 * class CallbackAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(string $input): string
 *     {
 *         return strtoupper($input);
 *     }
 * }
 *
 * // In tests:
 * CallbackAction::mock()
 *     ->shouldReceive('handle')
 *     ->andReturnUsing(function ($input) {
 *         return 'mocked: '.$input;
 *     });
 *
 * $result = CallbackAction::run('test');
 * expect($result)->toBe('mocked: test');
 * @example
 * // ============================================
 * // Example 16: Mock Protected Methods
 * // ============================================
 * class ProtectedMethodAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): string
 *     {
 *         return $this->getProtectedData();
 *     }
 *
 *     protected function getProtectedData(): string
 *     {
 *         return 'protected';
 *     }
 * }
 *
 * // In tests:
 * $mock = ProtectedMethodAction::mock();
 * $mock->shouldReceive('getProtectedData')->andReturn('mocked');
 *
 * $result = ProtectedMethodAction::run();
 * expect($result)->toBe('mocked');
 * @example
 * // ============================================
 * // Example 17: Mock with Ordered Calls
 * // ============================================
 * class OrderedAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(string $step): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * OrderedAction::mock()
 *     ->shouldReceive('handle')
 *     ->ordered()
 *     ->with('first')
 *     ->once();
 *
 * OrderedAction::mock()
 *     ->shouldReceive('handle')
 *     ->ordered()
 *     ->with('second')
 *     ->once();
 *
 * OrderedAction::run('first');
 * OrderedAction::run('second');
 * @example
 * // ============================================
 * // Example 18: Mock with Times
 * // ============================================
 * class TimesAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * TimesAction::shouldRun()->times(5);
 *
 * for ($i = 0; $i < 5; $i++) {
 *     TimesAction::run();
 * }
 * @example
 * // ============================================
 * // Example 19: Mock with At Least/At Most
 * // ============================================
 * class FlexibleAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * FlexibleAction::shouldRun()->atLeast()->times(2);
 * FlexibleAction::shouldRun()->atMost()->times(5);
 *
 * // Can be called 2-5 times
 * @example
 * // ============================================
 * // Example 20: Mock with Never
 * // ============================================
 * class NeverAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * NeverAction::shouldNotRun();
 * // or
 * NeverAction::shouldRun()->never();
 * @example
 * // ============================================
 * // Example 21: Mock with Any Arguments
 * // ============================================
 * class AnyArgsAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle($arg): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * AnyArgsAction::shouldRun()
 *     ->with(\Mockery::any())
 *     ->andReturnNull();
 *
 * AnyArgsAction::run('anything');
 * AnyArgsAction::run(123);
 * AnyArgsAction::run(['array']);
 * @example
 * // ============================================
 * // Example 22: Mock with Type Constraints
 * // ============================================
 * class TypedAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(User $user, int $count): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * TypedAction::shouldRun()
 *     ->with(\Mockery::type(User::class), \Mockery::type('int'))
 *     ->once();
 *
 * TypedAction::run($user, 5);
 * @example
 * // ============================================
 * // Example 23: Mock with Pattern Matching
 * // ============================================
 * class PatternAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(string $email): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * PatternAction::shouldRun()
 *     ->with(\Mockery::pattern('/@example\.com$/'))
 *     ->once();
 *
 * PatternAction::run('user@example.com');
 * @example
 * // ============================================
 * // Example 24: Mock with Closure Matching
 * // ============================================
 * class ClosureAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(int $value): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * ClosureAction::shouldRun()
 *     ->with(\Mockery::on(fn($value) => $value > 10))
 *     ->once();
 *
 * ClosureAction::run(15); // Matches
 * @example
 * // ============================================
 * // Example 25: Mock with Multiple Expectations
 * // ============================================
 * class MultiExpectAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(string $type): string
 *     {
 *         return $type;
 *     }
 * }
 *
 * // In tests:
 * $mock = MultiExpectAction::mock();
 * $mock->shouldReceive('handle')->with('type1')->andReturn('result1');
 * $mock->shouldReceive('handle')->with('type2')->andReturn('result2');
 *
 * expect(MultiExpectAction::run('type1'))->toBe('result1');
 * expect(MultiExpectAction::run('type2'))->toBe('result2');
 * @example
 * // ============================================
 * // Example 26: Mock in Feature Tests
 * // ============================================
 * class FeatureTestAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In feature test:
 * test('feature uses mocked action', function () {
 *     FeatureTestAction::shouldRun()->once();
 *
 *     $this->get('/endpoint-that-uses-action');
 *
 *     // Action was called
 * });
 * @example
 * // ============================================
 * // Example 27: Mock in Integration Tests
 * // ============================================
 * class IntegrationAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In integration test:
 * test('integration test', function () {
 *     IntegrationAction::spy(); // Use spy to track without affecting
 *
 *     // Run integration test
 *     $this->post('/api/endpoint');
 *
 *     // Verify action was called
 *     IntegrationAction::spy()->shouldHaveReceived('handle');
 * });
 * @example
 * // ============================================
 * // Example 28: Mock with Reset Between Tests
 * // ============================================
 * class ResetableAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * beforeEach(function () {
 *     ResetableAction::clearFake(); // Clear any previous fakes
 * });
 *
 * test('first test', function () {
 *     ResetableAction::shouldRun()->once();
 *     ResetableAction::run();
 * });
 *
 * test('second test', function () {
 *     // Fresh state, no fake
 *     ResetableAction::run(); // Uses real implementation
 * });
 * @example
 * // ============================================
 * // Example 29: Mock with Dependency Injection
 * // ============================================
 * class DependentAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function __construct(protected Service $service) {}
 *
 *     public function handle(): string
 *     {
 *         return $this->service->getData();
 *     }
 * }
 *
 * // In tests:
 * $service = Mockery::mock(Service::class);
 * $service->shouldReceive('getData')->andReturn('mocked');
 *
 * DependentAction::mock()
 *     ->shouldReceive('handle')
 *     ->andReturn('mocked');
 *
 * // Or inject mocked service:
 * app()->instance(Service::class, $service);
 * $result = DependentAction::run();
 * @example
 * // ============================================
 * // Example 30: Mock with Verification
 * // ============================================
 * class VerifiableAction extends Actions
 * {
 *     use AsFake;
 *
 *     public function handle(array $data): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // In tests:
 * $spy = VerifiableAction::spy();
 *
 * VerifiableAction::run(['key' => 'value']);
 *
 * // Verify with specific arguments
 * $spy->shouldHaveReceived('handle')
 *     ->with(['key' => 'value'])
 *     ->once();
 *
 * // Verify with any arguments
 * $spy->shouldHaveReceived('handle')->once();
 *
 * // Verify never called
 * $spy->shouldNotHaveReceived('handle');
 */
trait AsFake
{
    /**
     * Create a mock instance of the action.
     */
    public static function mock(): MockInterface
    {
        if (static::isFake()) {
            return static::getFakeResolvedInstance();
        }

        $mock = Mockery::mock(static::class);
        $mock->shouldAllowMockingProtectedMethods();

        return static::setFakeResolvedInstance($mock);
    }

    /**
     * Create a spy instance of the action.
     *
     * A spy tracks method calls but uses the real implementation.
     */
    public static function spy(): MockInterface
    {
        if (static::isFake()) {
            return static::getFakeResolvedInstance();
        }

        return static::setFakeResolvedInstance(Mockery::spy(static::class));
    }

    /**
     * Create a partial mock instance of the action.
     *
     * A partial mock uses the real implementation but allows mocking specific methods.
     */
    public static function partialMock(): MockInterface
    {
        return static::mock()->makePartial();
    }

    /**
     * Expect the handle() method to be called.
     */
    public static function shouldRun(): Expectation|ExpectationInterface|HigherOrderMessage
    {
        return static::mock()->shouldReceive('handle');
    }

    /**
     * Expect the handle() method NOT to be called.
     */
    public static function shouldNotRun(): Expectation|ExpectationInterface|HigherOrderMessage
    {
        return static::mock()->shouldNotReceive('handle');
    }

    /**
     * Allow the handle() method to be called (spy mode).
     */
    public static function allowToRun(): Expectation|ExpectationInterface|HigherOrderMessage|MockInterface
    {
        return static::spy()->allows('handle');
    }

    /**
     * Check if the action is currently faked.
     */
    public static function isFake(): bool
    {
        return app()->isShared(static::getFakeResolvedInstanceKey());
    }

    /**
     * Removes the fake instance from the container.
     */
    public static function clearFake(): void
    {
        app()->forgetInstance(static::getFakeResolvedInstanceKey());
    }

    /**
     * Set the fake resolved instance in the container.
     */
    protected static function setFakeResolvedInstance(MockInterface $fake): MockInterface
    {
        return app()->instance(static::getFakeResolvedInstanceKey(), $fake);
    }

    /**
     * Get the fake resolved instance from the container.
     */
    protected static function getFakeResolvedInstance(): ?MockInterface
    {
        return app(static::getFakeResolvedInstanceKey());
    }

    /**
     * Get the container key for the fake instance.
     */
    protected static function getFakeResolvedInstanceKey(): string
    {
        return 'LaravelActions:AsFake:'.static::class;
    }
}
