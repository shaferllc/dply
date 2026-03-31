<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLogger;

class TestLoggerFailingAction extends Actions
{
    use AsLogger;

    public function handle(): void
    {
        throw new \RuntimeException('Test error');
    }
}
