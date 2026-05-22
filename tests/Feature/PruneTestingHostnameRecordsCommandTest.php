<?php

declare(strict_types=1);

namespace Tests\Feature\PruneTestingHostnameRecordsCommandTest;

use App\Services\Sites\TestingHostnameRecordPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;

uses(RefreshDatabase::class);

test('no stale records emits clean message', function () {
    $mock = Mockery::mock(TestingHostnameRecordPruner::class);
    $mock->shouldReceive('staleRecords')->andReturn([]);
    $mock->shouldNotReceive('deleteRecord');
    $this->app->instance(TestingHostnameRecordPruner::class, $mock);

    $exit = Artisan::call('dply:prune-testing-hostname-records');

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No stale testing hostname A records found', Artisan::output());
});
test('dry run does not delete records', function () {
    $records = [[
        'zone' => 'example.com',
        'hostname' => 'old.example.com',
        'record_id' => 12345,
        'record_type' => 'A',
        'record_name' => 'old',
        'record_data' => '203.0.113.10',
    ]];
    $mock = Mockery::mock(TestingHostnameRecordPruner::class);
    $mock->shouldReceive('staleRecords')->andReturn($records);
    $mock->shouldNotReceive('deleteRecord');
    $this->app->instance(TestingHostnameRecordPruner::class, $mock);

    $exit = Artisan::call('dply:prune-testing-hostname-records', ['--dry-run' => true]);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    $this->assertStringContainsString('Dry run', $output);
    $this->assertStringContainsString('old.example.com', $output);
    $this->assertStringContainsString('12345', $output);
});
test('default run deletes each stale record', function () {
    $records = [
        [
            'zone' => 'example.com',
            'hostname' => 'old.example.com',
            'record_id' => 12345,
            'record_type' => 'A',
            'record_name' => 'old',
            'record_data' => '203.0.113.10',
        ],
        [
            'zone' => 'example.com',
            'hostname' => 'older.example.com',
            'record_id' => 12346,
            'record_type' => 'A',
            'record_name' => 'older',
            'record_data' => '203.0.113.11',
        ],
    ];
    $mock = Mockery::mock(TestingHostnameRecordPruner::class);
    $mock->shouldReceive('staleRecords')->andReturn($records);
    $mock->shouldReceive('deleteRecord')->twice();
    $this->app->instance(TestingHostnameRecordPruner::class, $mock);

    $exit = Artisan::call('dply:prune-testing-hostname-records');

    expect($exit)->toBe(0);
    $output = Artisan::output();
    $this->assertStringContainsString('Deleted stale testing hostname record old.example.com', $output);
    $this->assertStringContainsString('Deleted stale testing hostname record older.example.com', $output);
});
