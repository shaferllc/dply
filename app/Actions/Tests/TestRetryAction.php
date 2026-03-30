<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsRetry;

class TestRetryAction extends Actions
{
    use AsRetry;

    public int $attempts = 0;

    public int $maxRetries = 3;

    public function handle(): string
    {
        $this->attempts++;

        if ($this->attempts < 3) {
            throw new \RuntimeException('Temporary failure');
        }

        return 'success';
    }

    protected function getMaxRetries(): int
    {
        return $this->maxRetries;
    }
}
