<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLifecycle;

class TestLifecycleAction extends Actions
{
    use AsLifecycle;

    public string $commandSignature = 'test:lifecycle-action';

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
