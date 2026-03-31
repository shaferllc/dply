<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsCircuitBreaker;

class TestCircuitBreakerFailingAction extends Actions
{
    use AsCircuitBreaker;

    public string $commandSignature = 'test:circuit-breaker-failing-action';

    public int $calls = 0;

    public function handle(): void
    {
        $this->calls++;
        throw new \RuntimeException('Service unavailable');
    }

    protected function getFailureThreshold(): int
    {
        return 2;
    }
}
