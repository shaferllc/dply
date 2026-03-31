<?php

namespace Tests\Feature\Api;

use App\Models\ServerMetricIngestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetricsIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_when_token_not_configured(): void
    {
        config(['server_metrics.ingest.token' => '']);

        $this->postJson('/api/metrics', [], [
            'Authorization' => 'Bearer x',
        ])->assertStatus(503);
    }

    public function test_rejects_invalid_bearer_token(): void
    {
        config(['server_metrics.ingest.token' => 'correct-secret']);

        $this->postJson('/api/metrics', $this->validPayload(), [
            'Authorization' => 'Bearer wrong',
        ])->assertUnauthorized();
    }

    public function test_accepts_valid_payload_and_stores_event(): void
    {
        config(['server_metrics.ingest.token' => 'test-token']);

        $payload = $this->validPayload();

        $this->postJson('/api/metrics', $payload, [
            'Authorization' => 'Bearer test-token',
            'Accept' => 'application/json',
        ])->assertAccepted()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('server_metric_ingest_events', [
            'source_snapshot_id' => 42,
            'organization_id' => '01hzexampleorg0000000000000',
            'server_id' => '01hzexampleserver00000000000',
            'server_name' => 'web-1',
        ]);

        $row = ServerMetricIngestEvent::query()->firstOrFail();
        $this->assertSame(12.5, $row->metrics['cpu_pct']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'snapshot_id' => 42,
            'server_id' => '01hzexampleserver00000000000',
            'organization_id' => '01hzexampleorg0000000000000',
            'server_name' => 'web-1',
            'captured_at' => '2026-03-30T12:00:00Z',
            'metrics' => [
                'cpu_pct' => 12.5,
                'mem_pct' => 40.0,
                'disk_pct' => 55.0,
                'load_1m' => 0.5,
                'load_5m' => 0.4,
                'load_15m' => 0.3,
            ],
        ];
    }
}
