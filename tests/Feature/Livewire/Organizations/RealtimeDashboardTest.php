<?php

namespace Tests\Feature\Livewire\Organizations\RealtimeDashboardTest;

use App\Jobs\ProvisionRealtimeAppJob;
use App\Jobs\SyncOrganizationBillingJob;
use App\Livewire\Organizations\Realtime;
use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Force the cache-backed fake relay so provision/deprovision never hit
    // Cloudflare during the test.
    config()->set('realtime.fake.enabled', true);
    config()->set('realtime.tiers', [
        'starter' => ['label' => 'Starter', 'max_connections' => 5000, 'price_cents' => 1500],
        'growth' => ['label' => 'Growth', 'max_connections' => 25000, 'price_cents' => 4900],
        'scale' => ['label' => 'Scale', 'max_connections' => 100000, 'price_cents' => 14900],
    ]);
    config()->set('realtime.default_tier', 'starter');
});

/** @return array{0: User, 1: Organization} */
function orgWithRole(string $role): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);

    return [$user, $org];
}

test('the dashboard lists the org realtime apps and the monthly total', function () {
    [$user, $org] = orgWithRole('owner');
    RealtimeApp::factory()->for($org)->create(['name' => 'Alpha relay', 'tier' => 'starter']);
    RealtimeApp::factory()->for($org)->tier('growth')->create(['name' => 'Beta relay']);

    $this->actingAs($user);

    Livewire::test(Realtime::class, ['organization' => $org])
        ->assertSee('Alpha relay')
        ->assertSee('Beta relay')
        // 1500 + 4900 = 6400 cents added to the bill.
        ->assertSee('$64.00');
});

test('provisioning apps are not counted toward the monthly total', function () {
    [$user, $org] = orgWithRole('owner');
    RealtimeApp::factory()->for($org)->create(['tier' => 'starter']);
    RealtimeApp::factory()->for($org)->provisioning()->create(['tier' => 'growth']);

    $this->actingAs($user);

    // Only the active starter app ($15) bills; the provisioning growth one does not.
    Livewire::test(Realtime::class, ['organization' => $org])
        ->assertSee('$15.00');
});

test('changing tier updates the app, cap, and re-syncs relay + billing', function () {
    Queue::fake();
    [$user, $org] = orgWithRole('owner');
    $app = RealtimeApp::factory()->for($org)->create(['tier' => 'starter']);

    $this->actingAs($user);

    Livewire::test(Realtime::class, ['organization' => $org])
        ->call('startTierChange', $app->id)
        ->set('selectedTier', 'growth')
        ->set('confirmTierCharge', true)
        ->call('changeTier');

    $app->refresh();
    expect($app->tier)->toBe('growth');
    expect($app->max_connections)->toBe(25000);

    Queue::assertPushed(ProvisionRealtimeAppJob::class);
    Queue::assertPushed(SyncOrganizationBillingJob::class);
});

test('an upgrade without consent is rejected', function () {
    Queue::fake();
    [$user, $org] = orgWithRole('owner');
    $app = RealtimeApp::factory()->for($org)->create(['tier' => 'starter']);

    $this->actingAs($user);

    Livewire::test(Realtime::class, ['organization' => $org])
        ->call('startTierChange', $app->id)
        ->set('selectedTier', 'growth')
        ->set('confirmTierCharge', false)
        ->call('changeTier');

    expect($app->refresh()->tier)->toBe('starter');
    Queue::assertNotPushed(ProvisionRealtimeAppJob::class);
});

test('a downgrade does not require consent', function () {
    Queue::fake();
    [$user, $org] = orgWithRole('owner');
    $app = RealtimeApp::factory()->for($org)->tier('growth')->create();

    $this->actingAs($user);

    Livewire::test(Realtime::class, ['organization' => $org])
        ->call('startTierChange', $app->id)
        ->set('selectedTier', 'starter')
        ->set('confirmTierCharge', false)
        ->call('changeTier');

    expect($app->refresh()->tier)->toBe('starter');
    Queue::assertPushed(ProvisionRealtimeAppJob::class);
});

test('deleting an app removes it and triggers a billing resync', function () {
    Queue::fake();
    [$user, $org] = orgWithRole('owner');
    $app = RealtimeApp::factory()->for($org)->create();

    $this->actingAs($user);

    Livewire::test(Realtime::class, ['organization' => $org])
        ->call('confirmDelete', $app->id)
        ->call('deleteApp');

    $this->assertDatabaseMissing('realtime_apps', ['id' => $app->id]);
    Queue::assertPushed(SyncOrganizationBillingJob::class);
});

test('a non-admin member cannot change tier', function () {
    [$user, $org] = orgWithRole('member');
    $app = RealtimeApp::factory()->for($org)->create(['tier' => 'starter']);

    $this->actingAs($user);

    Livewire::test(Realtime::class, ['organization' => $org])
        ->call('startTierChange', $app->id)
        ->set('selectedTier', 'growth')
        ->set('confirmTierCharge', true)
        ->call('changeTier')
        ->assertStatus(403);

    expect($app->refresh()->tier)->toBe('starter');
});

test('apps from another org are not visible or reachable', function () {
    [$user, $org] = orgWithRole('owner');
    $otherOrg = Organization::factory()->create();
    $foreign = RealtimeApp::factory()->for($otherOrg)->create(['name' => 'Foreign relay']);

    $this->actingAs($user);

    Livewire::test(Realtime::class, ['organization' => $org])
        ->assertDontSee('Foreign relay')
        ->call('startTierChange', $foreign->id)
        ->assertStatus(404);
})->throws(ModelNotFoundException::class);
