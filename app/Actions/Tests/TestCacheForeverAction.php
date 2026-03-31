<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsCachedResult;

class TestCacheForeverAction extends Actions
{
    use AsCachedResult;

    public string $commandSignature = 'test:cache-forever-action';

    public function handle(): string
    {
        return 'permanent';
    }

    public function getCacheTtl(): int
    {
        return 86400 * 365; // 1 year - effectively "forever" for test
    }
}
