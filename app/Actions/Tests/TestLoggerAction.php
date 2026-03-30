<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLogger;

class TestLoggerAction extends Actions
{
    use AsLogger;

    public function handle(string $input): string
    {
        return "processed: {$input}";
    }
}
