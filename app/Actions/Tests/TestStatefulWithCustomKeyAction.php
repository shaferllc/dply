<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsStateful;

class TestStatefulWithCustomKeyAction extends Actions
{
    use AsStateful;

    public function handle(string $value): void
    {
        $this->setState(['value' => $value]);
    }

    protected function getStateKey(): string
    {
        return 'custom:state:key';
    }
}
