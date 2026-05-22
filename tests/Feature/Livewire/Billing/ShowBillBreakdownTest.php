<?php


namespace Tests\Feature\Livewire\Billing\ShowBillBreakdownTest;
use App\Livewire\Billing\Show as BillingShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'admin']);

    Config::set('subscription.standard.min_billable_age_days', 1);
});

test('empty fleet shows base only', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSee('Your bill')
        ->assertSee('dply base')
        ->assertSee('$15.00');
});

test('billable servers show in fleet table with their tier fees', function () {
    $matureM = server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');
    $matureL = server(org: $this->org, cpuCount: 8, memMb: 16384, ageDays: 5, name: 'db-1');

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
});

test('fresh servers are listed but marked not billed', function () {
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 0, name: 'fresh-server');

    $component = Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org]);

    $component->assertSee('fresh-server');
    $component->assertSee('Not billed');
    $component->assertSee('threshold');

    // Total stays at base only since the fresh server is excluded.
    $component->assertSee('$15.00');
});

test('non ready servers are marked not billed with status reason', function () {
    $server = server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'broken-1');
    $server->update(['status' => Server::STATUS_ERROR]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSee('broken-1')
        ->assertSee('Not billed')
        ->assertSee('error');
});

test('tier line items aggregate quantity per tier', function () {
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-1');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-2');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-3');

    $component = Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org]);

    $lineItems = $component->get('tierLineItems');

    $tierM = collect($lineItems)->firstWhere('label', 'dply server — M');
    expect($tierM)->not->toBeNull();
    expect($tierM['quantity'])->toBe(3);
    expect($tierM['line_cents'])->toBe(3000);
});

test('yearly total applies annual discount', function () {
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');

    $component = Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org]);

    // $25/mo × 12 × 0.8 = $240/yr
    expect($component->get('yearlyTotalCents'))->toBe(24000);
});

test('subscribe buttons show when org has a stripe id but no subscription', function () {
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
});

test('run rate line appears in the bill hero', function () {
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSee('Accruing')
        // $25/mo ÷ 30 ≈ $0.83/day
        ->assertSee('$0.83/day')
        ->assertSee('per server-day');
});

function server(Organization $org, int $cpuCount, int $memMb, int $ageDays, string $name): Server
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
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