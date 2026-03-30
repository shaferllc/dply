<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLock;

class TestLockAction extends Actions
{
    use AsLock;

    public string $commandSignature = 'test:lock-action';

    public int $executions = 0;

    public function handle(): string
    {
        $this->executions++;

        return 'locked';
    }
}
