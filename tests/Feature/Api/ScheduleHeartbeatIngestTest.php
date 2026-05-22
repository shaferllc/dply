<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Server;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the scheduler-heartbeat extension to the guest metrics push endpoint.
 * Heartbeats arrive in the same payload as metrics; ingest is best-effort —
 * a malformed heartbeat entry must not fail the whole push.
 */
class ScheduleHeartbeatIngestTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Server, Site, string} */
    private function serverWithSite(): array
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
    private function baseMetricsPayload(): array
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

    public function test_first_heartbeat_creates_row_with_first_seen_at(): void
    {
        [$server, $site, $token] = $this->serverWithSite();

        $this->postJson('/api/metrics', [
            'server_id' => $server->id,
            'token' => $token,
            'metrics' => $this->baseMetricsPayload(),
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

        $this->assertNotNull($row, 'heartbeat row was not created');
        $this->assertSame('laravel', $row->scheduler_kind);
        $this->assertSame('* * * * *', $row->cron_expression);
        $this->assertSame(0, $row->last_exit_code);
        $this->assertSame(840, $row->last_duration_ms);
        $this->assertSame(12000, $row->last_memory_peak_kb);
        $this->assertSame(0, $row->consecutive_misses);
        $this->assertFalse($row->circuit_open);
        $this->assertNotNull($row->first_seen_at);
    }

    public function test_subsequent_heartbeat_upserts_and_resets_misses_on_fresh_tick(): void
    {
        [$server, $site, $token] = $this->serverWithSite();

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
            'metrics' => $this->baseMetricsPayload(),
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
        $this->assertSame(0, $existing->consecutive_misses, 'fresh tick should reset misses');
        $this->assertEquals($freshTickAt->utc()->timestamp, $existing->last_tick_at->timestamp);
    }

    public function test_stale_or_repeated_tick_does_not_reset_consecutive_misses(): void
    {
        [$server, $site, $token] = $this->serverWithSite();

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
            'metrics' => $this->baseMetricsPayload(),
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
        $this->assertSame(3, $existing->consecutive_misses, 'non-fresh tick must not touch misses');
    }

    public function test_malformed_heartbeat_does_not_fail_the_whole_push(): void
    {
        [$server, $site, $token] = $this->serverWithSite();

        $this->postJson('/api/metrics', [
            'server_id' => $server->id,
            'token' => $token,
            'metrics' => $this->baseMetricsPayload(),
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

        $this->assertSame(
            1,
            ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->count(),
            'only the well-formed heartbeat should land'
        );
        $row = ServerSchedulerHeartbeat::query()->where('server_id', $server->id)->firstOrFail();
        $this->assertSame('*/5 * * * *', $row->cron_expression);
    }

    public function test_heartbeat_for_unknown_site_is_silently_skipped(): void
    {
        [$server, , $token] = $this->serverWithSite();

        // ULID-shaped but not a real site row.
        $this->postJson('/api/metrics', [
            'server_id' => $server->id,
            'token' => $token,
            'metrics' => $this->baseMetricsPayload(),
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

        $this->assertSame(0, ServerSchedulerHeartbeat::query()->count());
    }

    public function test_missing_scheduler_heartbeats_key_is_a_no_op(): void
    {
        // The old metrics payload (no scheduler_heartbeats key) must still succeed
        // and not create any heartbeat rows.
        [$server, , $token] = $this->serverWithSite();

        $this->postJson('/api/metrics', [
            'server_id' => $server->id,
            'token' => $token,
            'metrics' => $this->baseMetricsPayload(),
            'captured_at' => now()->toIso8601String(),
        ])->assertAccepted();

        $this->assertSame(0, ServerSchedulerHeartbeat::query()->count());
    }
}
