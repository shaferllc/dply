<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsCachedResult;

class TestCacheWithCustomKeyAction extends Actions
{
    use AsCachedResult;

    public string $commandSignature = 'test:cache-custom-key-action';

    public function handle(string $id): string
    {
        return "result: {$id}";
    }

    public function getCacheKey(string $id): string
    {
        return "custom:key:{$id}";
    }
}
