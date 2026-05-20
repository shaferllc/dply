<?php

namespace Tests\Feature\Services\Billing;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Services\Billing\OrganizationBillingStateComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class OrganizationBillingStateComputerTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationBillingStateComputer $computer;

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the existing assertions sharp by setting the age cutoff to zero
        // for these scenarios — a dedicated test below exercises the threshold
        // separately. Test factories create servers with `created_at = now()`,
        // which would otherwise be excluded by the default 1-day filter.
        Config::set('subscription.standard.min_billable_age_days', 0);
        $this->computer = app(OrganizationBillingStateComputer::class);
    }

    public function test_empty_org_returns_base_only(): void
    {
        $org = Organization::factory()->create();

        $state = $this->computer->compute($org);

        $this->assertSame(0, $state->serverCount());
        // $15 base + no servers, no credit.
        $this->assertSame(1500, $state->monthlyTotalCents);
    }

    public function test_classifies_each_ready_server_into_its_tier(): void
    {
        $org = Organization::factory()->create();
        $this->makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 4, memMb: 8192);   // M
        $this->makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 8, memMb: 16384);  // L
        $this->makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 1, memMb: 2048);   // XS

        $state = $this->computer->compute($org->fresh());

        $this->assertSame(3, $state->serverCount());
        $this->assertSame(1, $state->tierQuantities['m']);
        $this->assertSame(1, $state->tierQuantities['l']);
        $this->assertSame(1, $state->tierQuantities['xs']);
        // $15 base + ($10 + $20 + $2) = $47 = 4700 cents
        $this->assertSame(4700, $state->monthlyTotalCents);
    }

    public function test_excludes_non_ready_servers(): void
    {
        $org = Organization::factory()->create();
        $this->makeServerWithSpecs($org, status: Server::STATUS_PROVISIONING, cpuCount: 16, memMb: 32768);
        $this->makeServerWithSpecs($org, status: Server::STATUS_ERROR, cpuCount: 8, memMb: 16384);
        $this->makeServerWithSpecs($org, status: Server::STATUS_DISCONNECTED, cpuCount: 4, memMb: 8192);
        $this->makeServerWithSpecs($org, status: Server::STATUS_PENDING, cpuCount: 4, memMb: 8192);
        $this->makeServerWithSpecs($org, status: Server::STATUS_READY, cpuCount: 2, memMb: 4096);   // S, only billable

        $state = $this->computer->compute($org->fresh());

        $this->assertSame(1, $state->serverCount());
        $this->assertSame(1, $state->tierQuantities['s']);
        // $15 base + $5 S server = $20
        $this->assertSame(2000, $state->monthlyTotalCents);
    }

    public function test_servers_without_metrics_classify_as_xs(): void
    {
        $org = Organization::factory()->create();
        Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
        ]);

        $state = $this->computer->compute($org->fresh());

        $this->assertSame(1, $state->serverCount());
        $this->assertSame(1, $state->tierQuantities['xs']);
        // $15 base + $2 XS server = $17
        $this->assertSame(1700, $state->monthlyTotalCents);
    }

    public function test_ignores_servers_from_other_organizations(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $this->makeServerWithSpecs($otherOrg, status: Server::STATUS_READY, cpuCount: 16, memMb: 32768);

        $state = $this->computer->compute($org->fresh());

        $this->assertSame(0, $state->serverCount());
        $this->assertSame(1500, $state->monthlyTotalCents);
    }

    public function test_excludes_servers_younger_than_min_billable_age(): void
    {
        Config::set('subscription.standard.min_billable_age_days', 1);

        $org = Organization::factory()->create();

        // Fresh server — created right now, under the 1-day grace window.
        $fresh = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'created_at' => now(),
        ]);
        ServerMetricSnapshot::query()->create([
            'server_id' => $fresh->id,
            'captured_at' => now(),
            'payload' => ['cpu_count' => 4, 'mem_total_kb' => 8 * 1024 * 1024],
        ]);

        // Mature server — created 2 days ago, well past the threshold.
        $mature = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'created_at' => now()->subDays(2),
        ]);
        ServerMetricSnapshot::query()->create([
            'server_id' => $mature->id,
            'captured_at' => now(),
            'payload' => ['cpu_count' => 4, 'mem_total_kb' => 8 * 1024 * 1024],
        ]);

        $state = $this->computer->compute($org->fresh());

        $this->assertSame(1, $state->serverCount());
        $this->assertSame(1, $state->tierQuantities['m']);
        // $15 base + $10 M (the mature one only) = $25
        $this->assertSame(2500, $state->monthlyTotalCents);
    }

    public function test_age_threshold_is_inclusive_at_the_boundary(): void
    {
        Config::set('subscription.standard.min_billable_age_days', 1);

        $org = Organization::factory()->create();
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            // Exactly 1 day old (slightly older to dodge clock jitter).
            'created_at' => now()->subDay()->subSecond(),
        ]);
        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => ['cpu_count' => 1, 'mem_total_kb' => 2 * 1024 * 1024],
        ]);

        $state = $this->computer->compute($org->fresh());

        $this->assertSame(1, $state->serverCount());
    }

    private function makeServerWithSpecs(Organization $org, string $status, int $cpuCount, int $memMb): Server
    {
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => $status,
        ]);

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => [
                'cpu_count' => $cpuCount,
                'mem_total_kb' => $memMb * 1024,
            ],
        ]);

        return $server;
    }
}
