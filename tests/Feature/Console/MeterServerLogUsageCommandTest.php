<?php

declare(strict_types=1);

namespace Tests\Feature\Console\MeterServerLogUsageCommandTest;

use App\Models\Organization;
use App\Models\ServerLogUsageDaily;
use App\Services\Logs\ClickHouseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

uses(RefreshDatabase::class);

function fakeClickHouse(array $rows, bool $reachable = true): void
{
    $mock = Mockery::mock(ClickHouseClient::class);
    $mock->shouldReceive('ping')->andReturn($reachable);
    $mock->shouldReceive('qualifiedTable')->andReturn('dply_logs.server_logs');
    $mock->shouldReceive('select')->andReturn($rows);

    app()->instance(ClickHouseClient::class, $mock);
}

test('meters per-org ingest volume into the daily usage table', function () {
    $org = Organization::factory()->create();

    fakeClickHouse([
        ['org_id' => $org->id, 'events' => 1200, 'bytes' => 4096],
    ]);

    $this->artisan('dply:logs:meter', ['--date' => '2026-06-15'])
        ->expectsOutputToContain('Metered 2026-06-15')
        ->assertOk();

    $row = ServerLogUsageDaily::query()
        ->where('organization_id', $org->id)
        ->whereDate('day', '2026-06-15')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->source)->toBe(ServerLogUsageDaily::SOURCE_CLICKHOUSE);
    expect($row->events)->toBe(1200);
    expect($row->bytes)->toBe(4096);
});

test('re-metering the same day overwrites in place (idempotent)', function () {
    $org = Organization::factory()->create();

    fakeClickHouse([['org_id' => $org->id, 'events' => 10, 'bytes' => 100]]);
    $this->artisan('dply:logs:meter', ['--date' => '2026-06-15'])->assertOk();

    fakeClickHouse([['org_id' => $org->id, 'events' => 99, 'bytes' => 999]]);
    $this->artisan('dply:logs:meter', ['--date' => '2026-06-15'])->assertOk();

    expect(ServerLogUsageDaily::query()->where('organization_id', $org->id)->count())->toBe(1);
    $row = ServerLogUsageDaily::query()->where('organization_id', $org->id)->first();
    expect($row->events)->toBe(99);
    expect($row->bytes)->toBe(999);
});

test('skips ClickHouse org_ids that do not exist in Postgres', function () {
    $org = Organization::factory()->create();

    fakeClickHouse([
        ['org_id' => $org->id, 'events' => 5, 'bytes' => 50],
        ['org_id' => 'org_deleted_or_unknown', 'events' => 7, 'bytes' => 70],
    ]);

    $this->artisan('dply:logs:meter', ['--date' => '2026-06-15'])
        ->expectsOutputToContain('1 unknown-org group(s) skipped')
        ->assertOk();

    expect(ServerLogUsageDaily::query()->count())->toBe(1);
    expect(ServerLogUsageDaily::query()->where('organization_id', $org->id)->exists())->toBeTrue();
});

test('dry run reports volume without writing rows', function () {
    $org = Organization::factory()->create();

    fakeClickHouse([['org_id' => $org->id, 'events' => 10, 'bytes' => 100]]);

    $this->artisan('dply:logs:meter', ['--date' => '2026-06-15', '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')
        ->assertOk();

    expect(ServerLogUsageDaily::query()->count())->toBe(0);
});

test('unreachable ClickHouse store is a no-op, not a failure', function () {
    Organization::factory()->create();

    fakeClickHouse([], reachable: false);

    $this->artisan('dply:logs:meter')
        ->expectsOutputToContain('not reachable')
        ->assertOk();

    expect(ServerLogUsageDaily::query()->count())->toBe(0);
});
