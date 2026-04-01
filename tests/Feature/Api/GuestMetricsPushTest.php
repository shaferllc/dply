<?php

namespace Tests\Feature\Api;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestMetricsPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_push_stores_snapshot_with_valid_token(): void
    {
        config([
            'server_metrics.guest_push.enabled' => true,
            'server_metrics.ingest.enabled' => false,
        ]);

        $server = Server::factory()->create();

        $plain = 'test-plain-token-for-guest-push';
        $meta = $server->meta ?? [];
        $meta['monitoring_guest_push_token_hash'] = hash('sha256', $plain);
        $meta['monitoring_guest_push_cipher'] = encrypt($plain);
        $server->forceFill(['meta' => $meta])->saveQuietly();

        $metrics = [
            'cpu_pct' => 10.5,
            'mem_pct' => 20,
            'disk_pct' => 30,
            'load_1m' => 0.1,
            'load_5m' => 0.2,
            'load_15m' => 0.3,
            'mem_total_kb' => 1000000,
            'mem_available_kb' => 750000,
            'swap_total_kb' => 512000,
            'swap_used_kb' => 128000,
            'disk_total_bytes' => 100000000,
            'disk_used_bytes' => 50000000,
            'disk_free_bytes' => 50000000,
            'inode_pct_root' => 42.5,
            'cpu_count' => 4,
            'load_per_cpu_1m' => 0.03,
            'uptime_seconds' => 3600,
            'rx_bytes_per_sec' => 4096.5,
            'tx_bytes_per_sec' => 2048.25,
        ];

        $this->postJson('/api/metrics', [
            'server_id' => $server->id,
            'token' => $plain,
            'metrics' => $metrics,
            'captured_at' => '2026-03-30T12:00:00Z',
        ])->assertAccepted()->assertJson(['ok' => true]);

        $this->assertSame(1, ServerMetricSnapshot::query()->where('server_id', $server->id)->count());
        $snap = ServerMetricSnapshot::query()->where('server_id', $server->id)->firstOrFail();
        $this->assertSame(10.5, $snap->payload['cpu_pct']);
        $this->assertSame(750000, $snap->payload['mem_available_kb']);
        $this->assertSame(128000, $snap->payload['swap_used_kb']);
        $this->assertSame(42.5, $snap->payload['inode_pct_root']);
        $this->assertSame(4, $snap->payload['cpu_count']);
        $this->assertSame(4096.5, $snap->payload['rx_bytes_per_sec']);
    }

    public function test_guest_push_rejects_bad_token(): void
    {
        config([
            'server_metrics.guest_push.enabled' => true,
            'server_metrics.ingest.enabled' => false,
        ]);

        $server = Server::factory()->create();

        $plain = 'secret';
        $meta = $server->meta ?? [];
        $meta['monitoring_guest_push_token_hash'] = hash('sha256', $plain);
        $meta['monitoring_guest_push_cipher'] = encrypt($plain);
        $server->forceFill(['meta' => $meta])->saveQuietly();

        $this->postJson('/api/metrics', [
            'server_id' => $server->id,
            'token' => 'wrong',
            'metrics' => ['cpu_pct' => 1],
        ])->assertForbidden();
    }

    public function test_guest_push_normalizes_offsetless_timestamp_using_server_timezone(): void
    {
        config([
            'server_metrics.guest_push.enabled' => true,
            'server_metrics.ingest.enabled' => false,
        ]);

        $server = Server::factory()->create();

        $plain = 'test-plain-token-for-guest-push';
        $meta = $server->meta ?? [];
        $meta['monitoring_guest_push_token_hash'] = hash('sha256', $plain);
        $meta['monitoring_guest_push_cipher'] = encrypt($plain);
        $meta['timezone'] = 'America/Los_Angeles';
        $server->forceFill(['meta' => $meta])->saveQuietly();

        $this->postJson('/api/metrics', [
            'server_id' => $server->id,
            'token' => $plain,
            'metrics' => ['cpu_pct' => 10.5],
            'captured_at' => '2026-03-30 12:00:00',
        ])->assertAccepted()->assertJson(['ok' => true]);

        $snap = ServerMetricSnapshot::query()->where('server_id', $server->id)->firstOrFail();

        $this->assertSame('2026-03-30T19:00:00+00:00', $snap->captured_at->utc()->toIso8601String());
    }
}
