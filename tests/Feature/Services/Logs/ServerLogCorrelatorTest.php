<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Logs\ServerLogCorrelatorTest;

use App\Models\ErrorEvent;
use App\Models\Server;
use App\Services\Logs\ClickHouseClient;
use App\Services\Logs\ServerLogCorrelator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;

uses(RefreshDatabase::class);

function fakeClickHouseReturning(array $rows): void
{
    $ch = Mockery::mock(ClickHouseClient::class);
    $ch->shouldReceive('qualifiedTable')->andReturn('dply_logs.server_logs');
    $ch->shouldReceive('select')->andReturn($rows);
    app()->instance(ClickHouseClient::class, $ch);
}

test('forErrorEvent returns the log slice around the error on its server', function () {
    fakeClickHouseReturning([
        ['timestamp' => '2026-06-17 12:00:01', 'level' => 'error', 'source' => 'web', 'message' => '500'],
    ]);

    $server = Server::factory()->create();
    $error = new ErrorEvent;
    $error->server_id = $server->id;
    $error->occurred_at = Carbon::parse('2026-06-17 12:00:00', 'UTC');

    $result = app(ServerLogCorrelator::class)->forErrorEvent($error, 60, 60);

    expect($result)->not->toBeNull();
    expect($result['instant'])->toContain('2026-06-17T12:00:00');
    expect($result['from'])->toContain('2026-06-17T11:59:00');
    expect($result['to'])->toContain('2026-06-17T12:01:00');
    expect($result['logs'])->toHaveCount(1);
});

test('forErrorEvent returns null for an error with no owning server', function () {
    fakeClickHouseReturning([]);

    $error = new ErrorEvent; // no server_id

    expect(app(ServerLogCorrelator::class)->forErrorEvent($error))->toBeNull();
});

test('forErrorEvent returns null when the server no longer exists', function () {
    fakeClickHouseReturning([]);

    $error = new ErrorEvent;
    $error->server_id = 'srv_deleted';

    expect(app(ServerLogCorrelator::class)->forErrorEvent($error))->toBeNull();
});

test('inWindow pads the event window on both sides', function () {
    fakeClickHouseReturning([]);

    $server = Server::factory()->create();

    $result = app(ServerLogCorrelator::class)->inWindow(
        $server,
        Carbon::parse('2026-06-17 12:00:00', 'UTC'),
        Carbon::parse('2026-06-17 12:10:00', 'UTC'),
        30,
    );

    expect($result['from'])->toContain('2026-06-17T11:59:30');
    expect($result['to'])->toContain('2026-06-17T12:10:30');
});
