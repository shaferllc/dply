<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsTransaction;
use Illuminate\Support\Facades\DB;

class TestTransactionRollbackAction extends Actions
{
    use AsTransaction;

    public string $commandSignature = 'test:transaction-rollback-action';

    public function handle(): void
    {
        DB::table('test_table')->insert(['name' => 'test']);
        throw new \RuntimeException('Rollback test');
    }
}
