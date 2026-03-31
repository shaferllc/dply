<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsMetrics;

class TestMetricsAction extends Actions
{
    use AsMetrics;

    public function handle(): string
    {
        return 'success';
    }
}
