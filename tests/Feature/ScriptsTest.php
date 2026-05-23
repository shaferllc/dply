<?php

namespace Tests\Feature\ScriptsTest;

use App\Livewire\Scripts\Create;
use App\Livewire\Scripts\Marketplace;
use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('surface.scripts');

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('guest cannot view scripts', function () {
    $this->get(route('scripts.index'))->assertRedirect();
});

test('member can view scripts index', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('scripts.index'))
        ->assertOk()
        ->assertSee('Scripts', false)
        ->assertSee('organization-wide automation', false)
        ->assertSee('Script presets', false);
});

test('member can create script', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('name', 'My provisioner')
        ->set('content', "#!/bin/bash\necho ok\n")
        ->set('run_as_user', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('scripts', [
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'My provisioner',
    ]);
});

test('deployer cannot open scripts index', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    $this->actingAs($user)
        ->get(route('scripts.index'))
        ->assertForbidden();
});

test('marketplace clone creates marketplace sourced script', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    Livewire::actingAs($user)
        ->test(Marketplace::class)
        ->call('clonePreset', 'disk-usage-summary')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('scripts', [
        'organization_id' => $org->id,
        'source' => Script::SOURCE_MARKETPLACE,
        'marketplace_key' => 'disk-usage-summary',
    ]);
});

test('script presets page uses preset language', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('scripts.marketplace'))
        ->assertOk()
        ->assertSee('Script presets')
        ->assertSee('Saved commands');
});
