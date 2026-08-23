<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Billing;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\Billing\Livewire\Analytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function analyticsAdmin(): array
{
    $admin = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($admin->id, ['role' => 'admin']);

    return [$admin, $org];
}

test('the page leads with the projected total and its two supporting sections', function () {
    [$admin, $org] = analyticsAdmin();

    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
    ]);

    Livewire::actingAs($admin)
        ->test(Analytics::class, ['organization' => $org])
        ->assertOk()
        ->assertSee('Billing analytics')
        ->assertSee('Projected this month')
        ->assertSee('Where it goes')
        ->assertSee('Trend');
});

/*
 * The page used to run nine sections in one scroll. These assert the six that
 * were cut stay cut — three because they were dead once the Edge, Cloud and
 * Serverless surfaces were removed, one because it was operator telemetry on a
 * customer-facing page, one because it duplicated the observatory's server
 * table, and one because /billing/invoices already is that page.
 */
test('sections cut in the 2026-08-22 redesign do not come back', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::define('surface.cloud', fn () => true);
    Feature::define('surface.serverless', fn () => true);
    Feature::flushCache();

    [$admin, $org] = analyticsAdmin();

    Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);

    Livewire::actingAs($admin)
        ->test(Analytics::class, ['organization' => $org])
        ->assertDontSee('Edge sites')
        ->assertDontSee('Managed products')
        ->assertDontSee('Stripe sync events')
        ->assertDontSee('BYO server fleet')
        ->assertDontSee('Invoice history')
        // Vendor framing: this page is customer-facing, so the same figure is
        // their spend, not our revenue.
        ->assertDontSee('MRR')
        ->assertDontSee('Recurring revenue');
});

test('the cost observatory is one collapsed row, not three competing tiles', function () {
    Feature::define('global.billing_enabled', fn () => true);
    Feature::flushCache();

    [$admin, $org] = analyticsAdmin();

    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
        'meta' => ['cost_monthly_note' => '$5/mo Hetzner'],
    ]);

    Livewire::actingAs($admin)
        ->test(Analytics::class, ['organization' => $org])
        ->assertSee('Your provider costs')
        ->assertSee('We bill our work; you pay your cloud provider directly.')
        // The three-tile strip and its old heading are gone.
        ->assertDontSee('Transparent cost observatory')
        ->assertDontSee('Full stack estimate');
});

test('the reference tables are present but folded away', function () {
    [$admin, $org] = analyticsAdmin();

    Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);

    Livewire::actingAs($admin)
        ->test(Analytics::class, ['organization' => $org])
        ->assertSee('Line items')
        ->assertSeeHtml('<details');
});

test('billing analytics requires org update permission', function () {
    $member = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($member)
        ->test(Analytics::class, ['organization' => $org])
        ->assertForbidden();
});
