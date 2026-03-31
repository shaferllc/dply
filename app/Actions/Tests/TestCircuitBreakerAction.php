<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsCircuitBreaker;

class TestCircuitBreakerAction extends Actions
{
    use AsCircuitBreaker;

    public string $commandSignature = 'test:circuit-breaker-action';

    public int $failureThreshold = 3;

    public int $timeoutSeconds = 5;

    public function handle(): string
    {
        return 'success';
    }

    protected function getFailureThreshold(): int
    {
        return $this->failureThreshold;
    }

    protected function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }
}
