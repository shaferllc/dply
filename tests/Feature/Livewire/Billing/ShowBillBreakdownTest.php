<?php

namespace Tests\Feature\Livewire\Billing;

use App\Livewire\Billing\Show as BillingShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\TestCase;

class ShowBillBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->admin->id, ['role' => 'admin']);

        Config::set('subscription.standard.min_billable_age_days', 1);
    }

    public function test_empty_fleet_shows_base_only(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->assertSee('Your bill')
            ->assertSee('dply base')
            ->assertSee('$15.00');
    }

    public function test_billable_servers_show_in_fleet_table_with_their_tier_fees(): void
    {
        $matureM = $this->server(cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');
        $matureL = $this->server(cpuCount: 8, memMb: 16384, ageDays: 5, name: 'db-1');

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->assertSee('web-1')
            ->assertSee('db-1')
            ->assertSee('dply server — M')
            ->assertSee('dply server — L')
            ->assertSee('$10.00')
            ->assertSee('$20.00')
            // Total = $15 base + $10 M + $20 L = $45
            ->assertSee('$45.00');
    }

    public function test_fresh_servers_are_listed_but_marked_not_billed(): void
    {
        $this->server(cpuCount: 4, memMb: 8192, ageDays: 0, name: 'fresh-server');

        $component = Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org]);

        $component->assertSee('fresh-server');
        $component->assertSee('Not billed');
        $component->assertSee('threshold');
        // Total stays at base only since the fresh server is excluded.
        $component->assertSee('$15.00');
    }

    public function test_non_ready_servers_are_marked_not_billed_with_status_reason(): void
    {
        $server = $this->server(cpuCount: 4, memMb: 8192, ageDays: 5, name: 'broken-1');
        $server->update(['status' => Server::STATUS_ERROR]);

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->assertSee('broken-1')
            ->assertSee('Not billed')
            ->assertSee('error');
    }

    public function test_tier_line_items_aggregate_quantity_per_tier(): void
    {
        $this->server(cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-1');
        $this->server(cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-2');
        $this->server(cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-3');

        $component = Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org]);

        $lineItems = $component->get('tierLineItems');

        $tierM = collect($lineItems)->firstWhere('label', 'dply server — M');
        $this->assertNotNull($tierM);
        $this->assertSame(3, $tierM['quantity']);
        $this->assertSame(3000, $tierM['line_cents']);
    }

    public function test_yearly_total_applies_annual_discount(): void
    {
        $this->server(cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');

        $component = Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org]);

        // $25/mo × 12 × 0.8 = $240/yr
        $this->assertSame(24000, $component->get('yearlyTotalCents'));
    }

    public function test_subscribe_buttons_show_when_org_has_a_stripe_id_but_no_subscription(): void
    {
        // Simulate an abandoned checkout — Stripe customer record exists, but
        // no subscription was ever completed. canManageBilling must be false
        // so the Subscribe button is still offered.
        $this->org->update(['stripe_id' => 'cus_abandoned_checkout']);
        Config::set('subscription.standard.stripe.base_monthly', 'price_test_base');

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->assertSet('canManageBilling', false)
            // Empty fleet → $15.00/mo base. Button shows the amount inline.
            ->assertSee('Subscribe — $15.00/mo')
            ->assertSee('Pay yearly — save 20%');
    }

    public function test_run_rate_line_appears_in_the_bill_hero(): void
    {
        $this->server(cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->assertSee('Accruing')
            // $25/mo ÷ 30 ≈ $0.83/day
            ->assertSee('$0.83/day')
            ->assertSee('per server-day');
    }

    private function server(int $cpuCount, int $memMb, int $ageDays, string $name): Server
    {
        $server = Server::factory()->create([
            'organization_id' => $this->org->id,
            'status' => Server::STATUS_READY,
            'name' => $name,
            'created_at' => now()->subDays($ageDays),
        ]);

        ServerMetricSnapshot::query()->create([
            'server_id' => $server->id,
            'captured_at' => now(),
            'payload' => ['cpu_count' => $cpuCount, 'mem_total_kb' => $memMb * 1024],
        ]);

        return $server;
    }
}
