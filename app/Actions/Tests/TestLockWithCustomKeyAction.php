<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsLock;

class TestLockWithCustomKeyAction extends Actions
{
    use AsLock;

    public string $commandSignature = 'test:lock-custom-key-action';

    public function handle(int $id): string
    {
        return "processed: {$id}";
    }

    protected function getLockKey(int $id): string
    {
        return "lock:custom:{$id}";
    }
}
