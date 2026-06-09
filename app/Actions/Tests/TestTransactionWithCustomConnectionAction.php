<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsTransaction;

class TestTransactionWithCustomConnectionAction extends Actions
{
    use AsTransaction;

    public string $commandSignature = 'test:transaction-custom-connection-action';

    public function handle(): string
    {
        return 'success';
    }

    protected function getTransactionConnection(): string
    {
        return 'mysql';
    }
}
