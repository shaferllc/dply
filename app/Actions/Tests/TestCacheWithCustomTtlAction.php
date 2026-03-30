<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsCachedResult;

class TestCacheWithCustomTtlAction extends Actions
{
    use AsCachedResult;

    public string $commandSignature = 'test:cache-ttl-action';

    public function handle(): string
    {
        return 'result';
    }

    public function getCacheTtl(): int
    {
        return 3600; // 1 hour
    }
}
