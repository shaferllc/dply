<?php

namespace Tests\Feature\Api\MetricsIngestTest;

use App\Models\ServerMetricIngestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('rejects when token not configured', function () {
    config(['server_metrics.ingest.token' => '']);

    $this->postJson('/api/metrics', [], [
        'Authorization' => 'Bearer x',
    ])->assertStatus(503);
});

test('rejects invalid bearer token', function () {
    config(['server_metrics.ingest.token' => 'correct-secret']);

    $this->postJson('/api/metrics', validPayload(), [
        'Authorization' => 'Bearer wrong',
    ])->assertUnauthorized();
});

test('accepts valid payload and stores event', function () {
    config(['server_metrics.ingest.token' => 'test-token']);

    $payload = validPayload();

    $this->postJson('/api/metrics', $payload, [
        'Authorization' => 'Bearer test-token',
        'Accept' => 'application/json',
    ])->assertAccepted()->assertJson(['ok' => true]);

    $this->assertDatabaseHas('server_metric_ingest_events', [
        'source_snapshot_id' => 42,
        'organization_id' => '01hzexampleorg000000000000',
        'server_id' => '01hzexampleserver000000000',
        'server_name' => 'web-1',
    ]);

    $row = ServerMetricIngestEvent::query()->firstOrFail();
    expect($row->metrics['cpu_pct'])->toBe(12.5);
});

/**
 * @return array<string, mixed>
 */
function validPayload(): array
{
    return [
        'snapshot_id' => 42,
        'server_id' => '01hzexampleserver000000000',
        'organization_id' => '01hzexampleorg000000000000',
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
