<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsTransaction;
use Illuminate\Support\Facades\DB;

class TestTransactionAction extends Actions
{
    use AsTransaction;

    public string $commandSignature = 'test:transaction-action';

    public function handle(): string
    {
        DB::table('test_table')->insert(['name' => 'test']);

        return 'success';
    }
}
