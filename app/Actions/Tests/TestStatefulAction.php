<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsStateful;

class TestStatefulAction extends Actions
{
    use AsStateful;

    public function handle(string $step, array $data): void
    {
        $state = $this->getState();
        $state[$step] = $data;
        $this->setState($state);
    }
}
