<?php

namespace Tests\Feature\AdminDashboardTest;

use App\Livewire\Admin\Dashboard;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guest cannot access platform admin', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login', absolute: false));
});

test('authenticated user can open platform admin in testing environment', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
        ->assertSee(__('Platform admin'))
        ->assertSee(__('Runtime & optimization'))
        ->assertSee(__('Platform defaults (new orgs)'))
        ->assertSee(__('Organization feature flags'))
        ->assertSee(__('App-wide feature flags'))
        ->assertSee('provider.aws')
        ->assertSee('surface.serverless')
        ->assertSee('workspace.ephemeral_credentials')
        ->assertSee('global.billing_enabled')
        ->assertSee('included (20)');
});

test('platform admin can toggle org feature flag', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['slug' => 'flag-toggle-org']);
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('flagOrgId', (string) $org->id)
        ->call('toggleOrgFeatureFlag', 'workspace.ephemeral_credentials')
        ->assertSet('operationMessage', fn (?string $msg): bool => is_string($msg) && str_contains($msg, 'Ephemeral deploy credentials'));

    expect(Feature::for($org)->active('workspace.ephemeral_credentials'))->toBeTrue();
});

test('platform admin can disable org feature flag explicitly', function () {
    Feature::for(null)->activate('surface.serverless');

    $user = User::factory()->create();
    $org = Organization::factory()->create(['slug' => 'flag-disable-org']);
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    expect(Feature::for($org)->active('surface.serverless'))->toBeTrue();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('flagOrgId', (string) $org->id)
        ->call('toggleOrgFeatureFlag', 'surface.serverless');

    expect(Feature::for($org)->active('surface.serverless'))->toBeFalse();
});

test('platform default applies to new orgs until overridden', function () {
    Feature::purge('surface.serverless');
    Feature::for(null)->activate('surface.serverless');

    $org = Organization::factory()->create();

    expect(Feature::for($org)->active('surface.serverless'))->toBeTrue();

    Feature::for($org)->deactivate('surface.serverless');

    expect(Feature::for($org)->active('surface.serverless'))->toBeFalse();
});

test('platform admin can toggle platform default feature flag', function () {
    Feature::purge('surface.edge');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('togglePlatformDefaultFeatureFlag', 'surface.edge')
        ->assertSet('operationMessage', fn (?string $msg): bool => is_string($msg) && str_contains($msg, 'Edge'));

    expect(Feature::for(null)->active('surface.edge'))->toBeTrue();
});
