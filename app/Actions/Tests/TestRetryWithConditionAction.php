<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsRetry;

class TestRetryWithConditionAction extends Actions
{
    use AsRetry;

    public int $attempts = 0;

    public function handle(): string
    {
        $this->attempts++;

        if ($this->attempts < 2) {
            throw new \Error('Fatal error');
        }

        return 'success';
    }

    protected function shouldRetry(\Throwable $exception): bool
    {
        // Only retry RuntimeExceptions, not Errors
        return $exception instanceof \RuntimeException;
    }
}
