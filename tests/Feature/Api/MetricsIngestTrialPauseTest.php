<?php

namespace Tests\Feature\Api\MetricsIngestTrialPauseTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricIngestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('server_metrics.ingest.token', 'test-token');
    Config::set('subscription.standard.trial_days', 14);
    Config::set('subscription.standard.soft_pause_days', 30);
    // The pause ladder only applies to orgs that owe money. Count servers
    // immediately so the paid-fleet helper below takes effect.
    Config::set('subscription.standard.min_billable_age_days', 0);
});

function payingFleet(Organization $org): void
{
    // Two servers → a paid (Starter) plan, so the org is subject to pausing.
    Server::factory()->count(2)->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);
}

test('ingest succeeds for active trial org', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(5)]);

    $this->postJson('/api/metrics', payloadFor($org), [
        'Authorization' => 'Bearer test-token',
    ])->assertAccepted();

    expect(ServerMetricIngestEvent::query()->count())->toBe(1);
});

test('ingest still succeeds during soft pause', function () {
    // Soft-paused orgs keep reporting so dply UI stays accurate while
    // the customer is being prompted to add a card.
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);

    $this->postJson('/api/metrics', payloadFor($org), [
        'Authorization' => 'Bearer test-token',
    ])->assertAccepted();

    expect(ServerMetricIngestEvent::query()->count())->toBe(1);
});

test('ingest returns 402 during hard pause', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(45)]);
    payingFleet($org);

    $this->postJson('/api/metrics', payloadFor($org), [
        'Authorization' => 'Bearer test-token',
    ])->assertStatus(402);

    expect(ServerMetricIngestEvent::query()->count())->toBe(0);
});

test('ingest succeeds when org record does not exist', function () {
    // Belt-and-suspenders: an ingest event whose organization_id doesn't
    // match a known row should still record (some legacy paths may push
    // before the org exists in dply's tables). We only gate when we have
    // a definite org we know is hard-paused.
    $payload = payloadFor(Organization::factory()->make([
        'id' => '01nonexistentorgxx12345678',
    ]));

    $this->postJson('/api/metrics', $payload, [
        'Authorization' => 'Bearer test-token',
    ])->assertAccepted();
});

/**
 * @return array<string, mixed>
 */
function payloadFor(Organization $organization): array
{
    return [
        'snapshot_id' => 1,
        'server_id' => '01hzserveridforingest_test',
        'organization_id' => $organization->id,
        'server_name' => 'web-1',
        'captured_at' => '2026-05-01T00:00:00Z',
        'metrics' => [
            'cpu_pct' => 10.0,
            'mem_pct' => 30.0,
            'disk_pct' => 40.0,
        ],
    ];
}
