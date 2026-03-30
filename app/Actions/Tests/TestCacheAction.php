<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsCachedResult;

class TestCacheAction extends Actions
{
    use AsCachedResult;

    public string $commandSignature = 'test:cache-action';

    public int $executions = 0;

    public function handle(string $input): string
    {
        $this->executions++;

        return "cached: {$input}";
    }
}
