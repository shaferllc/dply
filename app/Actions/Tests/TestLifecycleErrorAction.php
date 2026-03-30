<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLifecycle;

class TestLifecycleErrorAction extends Actions
{
    use AsLifecycle;

    public string $commandSignature = 'test:lifecycle-error-action';

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
