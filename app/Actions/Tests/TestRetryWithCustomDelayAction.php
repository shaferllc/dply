<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsRetry;

class TestRetryWithCustomDelayAction extends Actions
{
    use AsRetry;

    public int $attempts = 0;

    public function handle(): string
    {
        $this->attempts++;

        if ($this->attempts < 2) {
            throw new \RuntimeException('Temporary failure');
        }

        return 'success';
    }

    protected function getMaxRetries(): int
    {
        return 2;
    }

    protected function getRetryDelay(): int
    {
        return 100; // 100ms
    }
}
