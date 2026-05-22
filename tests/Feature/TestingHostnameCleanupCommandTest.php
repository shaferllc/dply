<?php


namespace Tests\Feature\TestingHostnameCleanupCommandTest;
use Mockery;

use App\Services\Sites\TestingHostnameRecordPruner;

test('it reports stale records in dry run mode', function () {
    $pruner = Mockery::mock(TestingHostnameRecordPruner::class);
    $pruner->shouldReceive('staleRecords')->once()->andReturn([
        [
            'zone' => 'dply.cc',
            'hostname' => 'orphan-preview.dply.cc',
            'record_id' => 12,
            'record_type' => 'A',
            'record_name' => 'orphan-preview',
            'record_data' => '203.0.113.11',
        ],
    ]);
    $pruner->shouldNotReceive('deleteRecord');

    $this->app->instance(TestingHostnameRecordPruner::class, $pruner);

    $this->artisan('dply:prune-testing-hostname-records', ['--dry-run' => true])
        ->expectsOutput('Dry run: stale testing hostname record orphan-preview.dply.cc in dply.cc (record #12 → 203.0.113.11).')
        ->assertSuccessful();
});

test('it deletes stale records when not in dry run mode', function () {
    $record = [
        'zone' => 'dply.cc',
        'hostname' => 'orphan-preview.dply.cc',
        'record_id' => 12,
        'record_type' => 'A',
        'record_name' => 'orphan-preview',
        'record_data' => '203.0.113.11',
    ];

    $pruner = Mockery::mock(TestingHostnameRecordPruner::class);
    $pruner->shouldReceive('staleRecords')->once()->andReturn([$record]);
    $pruner->shouldReceive('deleteRecord')->once()->with($record);

    $this->app->instance(TestingHostnameRecordPruner::class, $pruner);

    $this->artisan('dply:prune-testing-hostname-records')
        ->expectsOutput('Deleted stale testing hostname record orphan-preview.dply.cc in dply.cc (record #12).')
        ->assertSuccessful();
});
