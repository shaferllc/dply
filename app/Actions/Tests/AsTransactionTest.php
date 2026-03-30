<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\Actions;
use App\Actions\Concerns\AsTransaction;
use App\Actions\Decorators\TransactionDecorator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

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

test('transaction commits on success', function () {
    DB::table('test_table')->truncate();

    $action = app(TestTransactionAction::class);
    $result = $action->handle();

    expect($result)->toBe('success')
        ->and(DB::table('test_table')->where('name', 'test')->exists())->toBeTrue();
});

test('transaction rolls back on failure', function () {
    DB::table('test_table')->truncate();

    $action = new TestTransactionRollbackAction;
    $decorator = new TransactionDecorator($action);

    expect(fn () => $decorator->handle())
        ->toThrow(\RuntimeException::class, 'Rollback test');

    expect(DB::table('test_table')->where('name', 'test')->exists())->toBeFalse();
})->skip('Nested transactions from LazilyRefreshDatabase cause MySQL savepoint errors');

test('transaction uses custom connection when specified', function () {
    $action = app(TestTransactionWithCustomConnectionAction::class);

    expect($action->handle())->toBe('success');
});
