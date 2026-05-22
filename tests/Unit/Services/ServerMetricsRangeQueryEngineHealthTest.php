<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use App\Services\Servers\ServerMetricsRangeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Exercises ServerMetricsRangeQuery::fetchEngineHealth — the per-engine
 * webserver/edge-proxy time-series extractor. Validates:
 *   - latest_block resolution from payload.webserver_health[]
 *   - gauge bucketing (active_connections)
 *   - counter→rate bucketing (requests_total → req/sec)
 *   - graceful no-data when the engine block is absent
 */
class ServerMetricsRangeQueryEngineHealthTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
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

    private function snapshot(Server $server, Carbon $at, array $webserverHealth): ServerMetricSnapshot
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

    public function test_latest_block_resolves_for_target_engine(): void
    {
        $server = $this->makeServer();
        $this->snapshot($server, now()->subSeconds(30), [
            ['engine' => 'nginx', 'active_connections' => 17, 'requests_total' => 1000],
            ['engine' => 'caddy', 'active_connections' => 3, 'requests_total' => 50],
        ]);

        $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'nginx', '1h');

        $this->assertSame('nginx', $out['engine']);
        $this->assertSame(17, $out['latest_block']['active_connections']);
        $this->assertSame(1000, $out['latest_block']['requests_total']);
    }

    public function test_latest_block_is_null_when_engine_absent(): void
    {
        $server = $this->makeServer();
        $this->snapshot($server, now()->subSeconds(30), [
            ['engine' => 'nginx', 'active_connections' => 17],
        ]);

        $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'traefik', '1h');

        $this->assertNull($out['latest_block']);
        $this->assertSame([], $out['metrics']['active_connections']);
    }

    public function test_active_connections_gauge_buckets_correctly(): void
    {
        $server = $this->makeServer();
        // Three snapshots in the last few minutes, all on the same 1m bucket
        // boundary won't help — space them out so each lands in its own
        // bucket for the 1h range (60s bucket).
        $base = now()->subMinutes(3)->startOfMinute();
        $this->snapshot($server, $base->copy(), [['engine' => 'nginx', 'active_connections' => 10]]);
        $this->snapshot($server, $base->copy()->addMinute(), [['engine' => 'nginx', 'active_connections' => 15]]);
        $this->snapshot($server, $base->copy()->addMinutes(2), [['engine' => 'nginx', 'active_connections' => 8]]);

        $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'nginx', '1h');

        $series = $out['metrics']['active_connections'];
        $this->assertCount(3, $series);
        $values = array_map(static fn ($p) => $p['avg'], $series);
        $this->assertEqualsCanonicalizing([10.0, 15.0, 8.0], $values);
    }

    public function test_requests_total_counter_converts_to_per_second_rate(): void
    {
        $server = $this->makeServer();
        // Two samples in the same 1-minute bucket: cumulative counter goes
        // 100 → 160 over 60 seconds. Expected rate = 60/60 = 1.0 req/sec.
        $bucket = now()->startOfMinute()->subMinute();
        $this->snapshot($server, $bucket->copy()->addSeconds(0), [['engine' => 'nginx', 'requests_total' => 100]]);
        $this->snapshot($server, $bucket->copy()->addSeconds(55), [['engine' => 'nginx', 'requests_total' => 160]]);

        $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'nginx', '1h');

        $rates = $out['metrics']['requests_per_sec'];
        $this->assertNotEmpty($rates);
        $this->assertEquals(1.0, $rates[0]['avg']);
    }

    public function test_errors_5xx_counter_converts_to_per_minute_rate(): void
    {
        $server = $this->makeServer();
        // 10 errors over a single 1m bucket → 10 errors/min.
        $bucket = now()->startOfMinute()->subMinute();
        $this->snapshot($server, $bucket->copy()->addSeconds(0), [['engine' => 'caddy', 'errors_5xx_total' => 5]]);
        $this->snapshot($server, $bucket->copy()->addSeconds(55), [['engine' => 'caddy', 'errors_5xx_total' => 15]]);

        $out = app(ServerMetricsRangeQuery::class)->fetchEngineHealth($server, 'caddy', '1h');

        $rates = $out['metrics']['errors_5xx_per_min'];
        $this->assertNotEmpty($rates);
        $this->assertEquals(10.0, $rates[0]['avg']);
    }
}
