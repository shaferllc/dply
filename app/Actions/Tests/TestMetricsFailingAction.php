<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsMetrics;

class TestMetricsFailingAction extends Actions
{
    use AsMetrics;

    public function handle(): void
    {
        throw new \RuntimeException('Test error');
    }
}
