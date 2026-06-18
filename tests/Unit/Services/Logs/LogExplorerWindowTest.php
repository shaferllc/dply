<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Logs\LogExplorerWindowTest;

use App\Models\Server;
use App\Services\Logs\ClickHouseClient;
use App\Services\Logs\LogExplorerQuery;
use Illuminate\Support\Carbon;
use Mockery;

/** @return array{0: LogExplorerQuery, 1: array<string, mixed>} */
function explorerCapturing(): array
{
    $captured = [];
    $ch = Mockery::mock(ClickHouseClient::class);
    $ch->shouldReceive('qualifiedTable')->andReturn('dply_logs.server_logs');
    $ch->shouldReceive('select')->andReturnUsing(function (string $sql, array $params) use (&$captured) {
        $captured['sql'] = $sql;
        $captured['params'] = $params;

        return [['timestamp' => '2026-06-17 12:02:00', 'message' => 'hit']];
    });

    return [new LogExplorerQuery($ch), $captured];
}

function fakeServer(): Server
{
    $server = new Server;
    $server->id = 'srv_1';
    $server->organization_id = 'org_1';

    return $server;
}

test('window queries a bounded range, chronologically, org+server scoped', function () {
    [$query, $captured] = explorerCapturing();

    $rows = $query->window(
        fakeServer(),
        Carbon::parse('2026-06-17 12:00:00', 'UTC'),
        Carbon::parse('2026-06-17 12:05:00', 'UTC'),
    );

    expect($rows)->toHaveCount(1);
    expect($captured['sql'])->toContain('timestamp >= {from:DateTime}');
    expect($captured['sql'])->toContain('timestamp <= {to:DateTime}');
    expect($captured['sql'])->toContain('ORDER BY timestamp ASC');
    expect($captured['sql'])->toContain('org_id = {org:String}');
    expect($captured['sql'])->toContain('server_id = {server:String}');
    expect($captured['params']['from'])->toBe('2026-06-17 12:00:00');
    expect($captured['params']['to'])->toBe('2026-06-17 12:05:00');
    expect($captured['params']['org'])->toBe('org_1');
    expect($captured['params']['server'])->toBe('srv_1');
});

test('around centers a ± second window on an instant', function () {
    [$query, $captured] = explorerCapturing();

    $query->around(fakeServer(), Carbon::parse('2026-06-17 12:00:00', 'UTC'), 120, 60);

    expect($captured['params']['from'])->toBe('2026-06-17 11:58:00'); // -120s
    expect($captured['params']['to'])->toBe('2026-06-17 12:01:00');   // +60s
});

test('facet filters are applied as bound params', function () {
    [$query, $captured] = explorerCapturing();

    $query->window(
        fakeServer(),
        Carbon::parse('2026-06-17 12:00:00', 'UTC'),
        Carbon::parse('2026-06-17 12:05:00', 'UTC'),
        ['source' => 'web', 'level' => 'error', 'search' => 'boom'],
    );

    expect($captured['sql'])->toContain('source = {source:String}');
    expect($captured['sql'])->toContain('level = {level:String}');
    expect($captured['sql'])->toContain('positionCaseInsensitive(message, {search:String})');
    expect($captured['params']['source'])->toBe('web');
    expect($captured['params']['level'])->toBe('error');
    expect($captured['params']['search'])->toBe('boom');
});
