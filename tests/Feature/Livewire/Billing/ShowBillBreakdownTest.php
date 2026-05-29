<?php

namespace Tests\Feature\Livewire\Billing\ShowBillBreakdownTest;

use App\Livewire\Billing\Show as BillingShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'admin']);

    Config::set('subscription.standard.min_billable_age_days', 1);
});

test('empty fleet shows the free plan at no cost', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSee('Your bill')
        ->assertSee('dply plan — Free')
        ->assertSee('$0.00');
});

test('billable servers show in the fleet table and drive the plan', function () {
    // Two servers → Starter ($9 flat), regardless of size.
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');
    server(org: $this->org, cpuCount: 8, memMb: 16384, ageDays: 5, name: 'db-1');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSee('web-1')
        ->assertSee('db-1')
        ->assertSee('dply plan — Starter')
        ->assertSee('Included in plan')
        // Total = Starter $9 flat.
        ->assertSee('$9.00');
});

test('a single server stays on the free plan', function () {
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'solo-1');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSee('solo-1')
        ->assertSee('dply plan — Free')
        ->assertSee('$0.00');
});

test('fresh servers are listed but marked not billed', function () {
    // One mature server keeps the org on a paid plan; the fresh one is excluded.
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'mature-1');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'mature-2');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 0, name: 'fresh-server');

    $component = Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org]);

    $component->assertSee('fresh-server');
    $component->assertSee('Not billed');
    $component->assertSee('threshold');

    // Two mature servers → Starter; the fresh one doesn't bump the plan.
    $component->assertSee('dply plan — Starter');
    $component->assertSee('$9.00');
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

test('the bill carries a single flat plan line', function () {
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-1');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-2');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'app-3');

    $component = Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org]);

    $lineItems = $component->get('tierLineItems');

    // 3 servers → Starter, one flat line, no per-size lines.
    expect($lineItems)->toHaveCount(1);
    $plan = collect($lineItems)->firstWhere('label', 'dply plan — Starter');
    expect($plan)->not->toBeNull();
    expect($plan['quantity'])->toBe(1);
    expect($plan['line_cents'])->toBe(900);
});

test('yearly total applies annual discount', function () {
    // Two servers → Starter ($9/mo). $9 × 12 × 0.8 = $86.40/yr.
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-2');

    $component = Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org]);

    expect($component->get('yearlyTotalCents'))->toBe(8640);
});

test('subscribe buttons show when org has a stripe id but no subscription', function () {
    // Simulate an abandoned checkout — Stripe customer record exists, but
    // no subscription was ever completed. canManageBilling must be false
    // so the Subscribe button is still offered.
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-2');
    $this->org->update(['stripe_id' => 'cus_abandoned_checkout']);
    Config::set('subscription.standard.stripe.plans.starter', 'price_test_starter');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSet('canManageBilling', false)
        // Two servers → Starter $9.00/mo. Button shows the amount inline.
        ->assertSee('Subscribe — $9.00/mo')
        ->assertSee('Pay yearly — save 20%');
});

test('plan line appears in the bill hero', function () {
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-1');
    server(org: $this->org, cpuCount: 4, memMb: 8192, ageDays: 5, name: 'web-2');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSee('Your plan')
        ->assertSee('Starter');
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
