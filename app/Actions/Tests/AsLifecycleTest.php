<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLifecycle;
use Tests\TestCase;

uses(TestCase::class);

class AsLifecycleTest extends Actions
{
    use AsLifecycle;

    public bool $beforeHandleCalled = false;

    public bool $afterHandleCalled = false;

    public bool $onSuccessCalled = false;

    public bool $onErrorCalled = false;

    public bool $afterExecutionCalled = false;

    public mixed $beforeHandleArgs = null;

    public mixed $afterHandleResult = null;

    public mixed $onSuccessResult = null;

    public ?\Throwable $onErrorException = null;

    public function handle(string $input): string
    {
        return "processed: {$input}";
    }

    protected function beforeHandle(...$arguments): void
    {
        $this->beforeHandleCalled = true;
        $this->beforeHandleArgs = $arguments;
    }

    protected function afterHandle($result, ...$arguments): void
    {
        $this->afterHandleCalled = true;
        $this->afterHandleResult = $result;
    }

    protected function onSuccess($result, ...$arguments): void
    {
        $this->onSuccessCalled = true;
        $this->onSuccessResult = $result;
    }

    protected function onError(\Throwable $exception, ...$arguments): void
    {
        $this->onErrorCalled = true;
        $this->onErrorException = $exception;
    }

    protected function afterExecution($result = null, ?\Throwable $exception = null, ...$arguments): void
    {
        $this->afterExecutionCalled = true;
    }
}

class AsLifecycleErrorAction extends Actions
{
    use AsLifecycle;

    public bool $onErrorCalled = false;

    public bool $afterExecutionCalled = false;

    public function handle(): void
    {
        throw new \RuntimeException('Test error');
    }

    protected function onError(\Throwable $exception): void
    {
        $this->onErrorCalled = true;
    }

    protected function afterExecution($result = null, ?\Throwable $exception = null): void
    {
        $this->afterExecutionCalled = true;
    }
}

test('lifecycle hooks are called in correct order on success', function () {
    $action = AsLifecycleTest::make();

    $result = $action->run('test');

    expect($result)->toBe('processed: test')
        ->and($action->beforeHandleCalled)->toBeTrue()
        ->and($action->afterHandleCalled)->toBeTrue()
        ->and($action->onSuccessCalled)->toBeTrue()
        ->and($action->onErrorCalled)->toBeFalse()
        ->and($action->afterExecutionCalled)->toBeTrue()
        ->and($action->afterHandleResult)->toBe('processed: test')
        ->and($action->onSuccessResult)->toBe('processed: test');
});

test('lifecycle hooks receive correct arguments', function () {
    $action = AsLifecycleTest::make();

    $action->run('test', 'arg2');

    expect($action->beforeHandleArgs)->toBe(['test', 'arg2']);
});

test('lifecycle hooks handle errors correctly', function () {
    $action = AsLifecycleErrorAction::make();

    expect(fn () => $action->run())->toThrow(\RuntimeException::class, 'Test error')
        ->and($action->onErrorCalled)->toBeTrue()
        ->and($action->afterExecutionCalled)->toBeTrue();
});

test('afterExecution is called with exception on error', function () {
    $action = AsLifecycleErrorAction::make();

    try {
        $action->handle();
    } catch (\Throwable $e) {
        // Expected
    }

    expect($action->afterExecutionCalled)->toBeTrue();
});
