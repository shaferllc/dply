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
            'disk_total_bytes' => 100000000,
            'disk_used_bytes' => 50000000,
        ];

        $this->postJson('/api/metrics/guest-push', [
            'server_id' => $server->id,
            'token' => $plain,
            'metrics' => $metrics,
            'captured_at' => '2026-03-30T12:00:00Z',
        ])->assertAccepted()->assertJson(['ok' => true]);

        $this->assertSame(1, ServerMetricSnapshot::query()->where('server_id', $server->id)->count());
        $snap = ServerMetricSnapshot::query()->where('server_id', $server->id)->firstOrFail();
        $this->assertSame(10.5, $snap->payload['cpu_pct']);
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

        $this->postJson('/api/metrics/guest-push', [
            'server_id' => $server->id,
            'token' => 'wrong',
            'metrics' => ['cpu_pct' => 1],
        ])->assertForbidden();
    }
}
