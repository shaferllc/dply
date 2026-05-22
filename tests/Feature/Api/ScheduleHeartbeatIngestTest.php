<?php

declare(strict_types=1);

namespace Tests\Feature\Api\ScheduleHeartbeatIngestTest;

use App\Models\Server;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{Server, Site, string} */
function serverWithSite(): array
{
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);

    config([
        'server_metrics.guest_push.enabled' => true,
        'server_metrics.ingest.enabled' => false,
    ]);

    $plain = 'test-plain-token-'.bin2hex(random_bytes(8));
    $meta = $server->meta ?? [];
    $meta['monitoring_guest_push_token_hash'] = hash('sha256', $plain);
    $meta['monitoring_guest_push_cipher'] = encrypt($plain);
    $server->forceFill(['meta' => $meta])->saveQuietly();

    return [$server, $site, $plain];
}
/** @return array<string, mixed> */
function baseMetricsPayload(): array
{
    return [
        'cpu_pct' => 1.0,
        'mem_pct' => 1.0,
        'disk_pct' => 1.0,
        'load_1m' => 0.0,
        'load_5m' => 0.0,
        'load_15m' => 0.0,
        'mem_total_kb' => 1000,
        'mem_available_kb' => 900,
        'swap_total_kb' => 0,
        'swap_used_kb' => 0,
        'disk_total_bytes' => 1000,
        'disk_used_bytes' => 100,
        'disk_free_bytes' => 900,
        'inode_pct_root' => 1.0,
        'cpu_count' => 1,
        'load_per_cpu_1m' => 0.0,
        'uptime_seconds' => 60,
        'rx_bytes_per_sec' => 0,
        'tx_bytes_per_sec' => 0,
    ];
}
test('first heartbeat creates row with first seen at', function () {
    [$server, $site, $token] = serverWithSite();

    $this->postJson('/api/metrics', [
        'server_id' => $server->id,
        'token' => $token,
        'metrics' => baseMetricsPayload(),
        'captured_at' => now()->toIso8601String(),
        'scheduler_heartbeats' => [
            [
                'v' => 1,
                'site_id' => $site->id,
                'scheduler_kind' => 'laravel',
                'cron_expression' => '* * * * *',
                'last_tick_at' => now()->subSeconds(30)->toIso8601String(),
                'exit_code' => 0,
                'duration_ms' => 840,
                'memory_peak_kb' => 12000,
                'circuit_open' => false,
            ],
        ],
    ])->assertAccepted();

    $row = ServerSchedulerHeartbeat::query()
        ->where('server_id', $server->id)
        ->where('site_id', $site->id)
        ->first();

    expect($row)->not->toBeNull('heartbeat row was not created');
    expect($row->scheduler_kind)->toBe('laravel');
    expect($row->cron_expression)->toBe('* * * * *');
    expect($row->last_exit_code)->toBe(0);
    expect($row->last_duration_ms)->toBe(840);
    expect($row->last_memory_peak_kb)->toBe(12000);
    expect($row->consecutive_misses)->toBe(0);
    expect($row->circuit_open)->toBeFalse();
    expect($row->first_seen_at)->not->toBeNull();
});
test('subsequent heartbeat upserts and resets misses on fresh tick', function () {
    [$server, $site, $token] = serverWithSite();

    // Seed an existing row with consecutive_misses > 0 (simulating the
    // Insights runner had been incrementing it because no ticks were landing).
    $existing = ServerSchedulerHeartbeat::factory()
        ->state([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'scheduler_kind' => 'laravel',
            'last_tick_at' => now()->subMinutes(10),
            'consecutive_misses' => 7,
        ])
        ->create();

    $freshTickAt = now()->subSeconds(5);

    $this->postJson('/api/metrics', [
        'server_id' => $server->id,
        'token' => $token,
        'metrics' => baseMetricsPayload(),
        'captured_at' => now()->toIso8601String(),
        'scheduler_heartbeats' => [
            [
                'v' => 1,
                'site_id' => $site->id,
                'scheduler_kind' => 'laravel',
                'cron_expression' => '* * * * *',
                'last_tick_at' => $freshTickAt->toIso8601String(),
                'exit_code' => 0,
            ],
        ],
    ])->assertAccepted();

    $existing->refresh();
    expect($existing->consecutive_misses)->toBe(0, 'fresh tick should reset misses');
    expect($existing->last_tick_at->timestamp)->toEqual($freshTickAt->utc()->timestamp);
});
test('stale or repeated tick does not reset consecutive misses', function () {
    [$server, $site, $token] = serverWithSite();

    $existingTickAt = now()->subMinutes(2);
    $existing = ServerSchedulerHeartbeat::factory()
        ->state([
            'server_id' => $server->id,
            'site_id' => $site->id,
            'scheduler_kind' => 'laravel',
            'last_tick_at' => $existingTickAt,
            'consecutive_misses' => 3,
        ])
        ->create();

    $this->postJson('/api/metrics', [
        'server_id' => $server->id,
        'token' => $token,
        'metrics' => baseMetricsPayload(),
        'captured_at' => now()->toIso8601String(),
        'scheduler_heartbeats' => [
            [
                'v' => 1,
                'site_id' => $site->id,
                'scheduler_kind' => 'laravel',
                'cron_expression' => '* * * * *',
                // Same timestamp as what's already stored — not a fresh tick.
                'last_tick_at' => $existingTickAt->toIso8601String(),
                'exit_code' => 0,
            ],
        ],
    ])->assertAccepted();

    $existing->refresh();
    expect($existing->consecutive_misses)->toBe(3, 'non-fresh tick must not touch misses');
});
test('malformed heartbeat does not fail the whole push', function () {
    [$server, $site, $token] = serverWithSite();

    $this->postJson('/api/metrics', [
        'server_id' => $server->id,
        'token' => $token,
        'metrics' => baseMetricsPayload(),
        'captured_at' => now()->toIso8601String(),
        'scheduler_heartbeats' => [
            // Bad: missing site_id
            ['v' => 1, 'scheduler_kind' => 'laravel', 'cron_expression' => '* * * * *'],
            // Bad: unknown kind
            ['v' => 1, 'site_id' => $site->id, 'scheduler_kind' => 'cobol', 'cron_expression' => '* * * * *'],
            // Bad: unsupported version
            ['v' => 999, 'site_id' => $site->id, 'scheduler_kind' => 'laravel', 'cron_expression' => '* * * * *'],
            // Good entry — should still land despite siblings being bad
            [
                'v' => 1,
                'site_id' => $site->id,
                'scheduler_kind' => 'laravel',
                'cron_expression' => '*/5 * * * *',
                'last_tick_at' => now()->subSeconds(10)->toIso8601String(),
                'exit_code' => 0,
            ],
        ],
    ])->assertAccepted();

    expect(ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count())->toBe(1, 'only the well-formed heartbeat should land');
    $row = ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->firstOrFail();
    expect($row->cron_expression)->toBe('*/5 * * * *');
});
test('heartbeat for unknown site is silently skipped', function () {
    [$server, , $token] = serverWithSite();

    // ULID-shaped but not a real site row.
    $this->postJson('/api/metrics', [
        'server_id' => $server->id,
        'token' => $token,
        'metrics' => baseMetricsPayload(),
        'captured_at' => now()->toIso8601String(),
        'scheduler_heartbeats' => [
            [
                'v' => 1,
                'site_id' => '01XXXXXXXXXXXXXXXXXXXXXXXXAA',
                'scheduler_kind' => 'laravel',
                'cron_expression' => '* * * * *',
            ],
        ],
    ])->assertAccepted();

    expect(ServerSchedulerHeartbeat::query()->count())->toBe(0);
});
test('missing scheduler heartbeats key is a no op', function () {
    // The old metrics payload (no scheduler_heartbeats key) must still succeed
    // and not create any heartbeat rows.
    [$server, , $token] = serverWithSite();

    $this->postJson('/api/metrics', [
        'server_id' => $server->id,
        'token' => $token,
        'metrics' => baseMetricsPayload(),
        'captured_at' => now()->toIso8601String(),
    ])->assertAccepted();

    expect(ServerSchedulerHeartbeat::query()->count())->toBe(0);
});
