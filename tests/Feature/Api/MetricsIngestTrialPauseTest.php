<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\ServerMetricIngestEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MetricsIngestTrialPauseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('server_metrics.ingest.token', 'test-token');
        Config::set('subscription.standard.trial_days', 14);
        Config::set('subscription.standard.soft_pause_days', 30);
    }

    public function test_ingest_succeeds_for_active_trial_org(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(5)]);

        $this->postJson('/api/metrics', $this->payloadFor($org), [
            'Authorization' => 'Bearer test-token',
        ])->assertAccepted();

        $this->assertSame(1, ServerMetricIngestEvent::query()->count());
    }

    public function test_ingest_still_succeeds_during_soft_pause(): void
    {
        // Soft-paused orgs keep reporting so dply UI stays accurate while
        // the customer is being prompted to add a card.
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);

        $this->postJson('/api/metrics', $this->payloadFor($org), [
            'Authorization' => 'Bearer test-token',
        ])->assertAccepted();

        $this->assertSame(1, ServerMetricIngestEvent::query()->count());
    }

    public function test_ingest_returns_402_during_hard_pause(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(45)]);

        $this->postJson('/api/metrics', $this->payloadFor($org), [
            'Authorization' => 'Bearer test-token',
        ])->assertStatus(402);

        $this->assertSame(0, ServerMetricIngestEvent::query()->count());
    }

    public function test_ingest_succeeds_when_org_record_does_not_exist(): void
    {
        // Belt-and-suspenders: an ingest event whose organization_id doesn't
        // match a known row should still record (some legacy paths may push
        // before the org exists in dply's tables). We only gate when we have
        // a definite org we know is hard-paused.
        $payload = $this->payloadFor(Organization::factory()->make([
            'id' => '01nonexistentorgxx12345678',
        ]));

        $this->postJson('/api/metrics', $payload, [
            'Authorization' => 'Bearer test-token',
        ])->assertAccepted();
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Organization $organization): array
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
}
