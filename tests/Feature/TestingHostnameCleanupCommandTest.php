<?php

namespace Tests\Feature;

use App\Console\Commands\PruneTestingHostnameRecordsCommand;
use App\Services\Sites\TestingHostnameRecordPruner;
use Mockery;
use Tests\TestCase;

class TestingHostnameCleanupCommandTest extends TestCase
{
    public function test_it_reports_stale_records_in_dry_run_mode(): void
    {
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
    }

    public function test_it_deletes_stale_records_when_not_in_dry_run_mode(): void
    {
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
    }
}
