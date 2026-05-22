<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerMetricsRangeQueryEngineHealthTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use App\Services\Servers\ServerMetricsRangeQuery;
use Illuminate\Support\Carbon;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
}
function snapshot(Server $server, Carbon $at, array $webserverHealth): ServerMetricSnapshot
{
    return ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => $at,
        'payload' => [
            'cpu_pct' => 5.0,
            'webserver_health' => $webserverHealth,
        ],
    ]);
}
test('latest block resolves for target engine', function () {
    $server = makeServer();
    snapshot($server, now()->subSeconds(30), [
        ['engine' => 'nginx', 'active_connections' => 17, 'requests_total' => 1000],
        ['engine' => 'caddy', 'active_connections' => 3, 'requests_total' => 50],
    ]);

    $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'nginx', '1h');

    expect($out['engine'])->toBe('nginx');
    expect($out['latest_block']['active_connections'])->toBe(17);
    expect($out['latest_block']['requests_total'])->toBe(1000);
});
test('latest block is null when engine absent', function () {
    $server = makeServer();
    snapshot($server, now()->subSeconds(30), [
        ['engine' => 'nginx', 'active_connections' => 17],
    ]);

    $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'traefik', '1h');

    expect($out['latest_block'])->toBeNull();
    expect($out['metrics']['active_connections'])->toBe([]);
});
test('active connections gauge buckets correctly', function () {
    $server = makeServer();

    // Three snapshots in the last few minutes, all on the same 1m bucket
    // boundary won't help — space them out so each lands in its own
    // bucket for the 1h range (60s bucket).
    $base = now()->subMinutes(3)->startOfMinute();
    snapshot($server, $base->copy(), [['engine' => 'nginx', 'active_connections' => 10]]);
    snapshot($server, $base->copy()->addMinute(), [['engine' => 'nginx', 'active_connections' => 15]]);
    snapshot($server, $base->copy()->addMinutes(2), [['engine' => 'nginx', 'active_connections' => 8]]);

    $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'nginx', '1h');

    $series = $out['metrics']['active_connections'];
    expect($series)->toHaveCount(3);
    $values = array_map(static fn ($p) => $p['avg'], $series);
    expect($values)->toEqualCanonicalizing([10.0, 15.0, 8.0]);
});
test('requests total counter converts to per second rate', function () {
    $server = makeServer();

    // Two samples in the same 1-minute bucket: cumulative counter goes
    // 100 → 160 over 60 seconds. Expected rate = 60/60 = 1.0 req/sec.
    $bucket = now()->startOfMinute()->subMinute();
    snapshot($server, $bucket->copy()->addSeconds(0), [['engine' => 'nginx', 'requests_total' => 100]]);
    snapshot($server, $bucket->copy()->addSeconds(55), [['engine' => 'nginx', 'requests_total' => 160]]);

    $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'nginx', '1h');

    $rates = $out['metrics']['requests_per_sec'];
    expect($rates)->not->toBeEmpty();
    expect($rates[0]['avg'])->toEqual(1.0);
});
test('errors 5xx counter converts to per minute rate', function () {
    $server = makeServer();

    // 10 errors over a single 1m bucket → 10 errors/min.
    $bucket = now()->startOfMinute()->subMinute();
    snapshot($server, $bucket->copy()->addSeconds(0), [['engine' => 'caddy', 'errors_5xx_total' => 5]]);
    snapshot($server, $bucket->copy()->addSeconds(55), [['engine' => 'caddy', 'errors_5xx_total' => 15]]);

    $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'caddy', '1h');

    $rates = $out['metrics']['errors_5xx_per_min'];
    expect($rates)->not->toBeEmpty();
    expect($rates[0]['avg'])->toEqual(10.0);
});
