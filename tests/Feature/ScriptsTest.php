<?php

namespace Tests\Feature\ScriptsTest;

use App\Modules\Marketplace\Livewire\Scripts\Create;
use App\Modules\Marketplace\Livewire\Index as MarketplaceIndex;
use App\Modules\Marketplace\Livewire\Scripts\Index;
use App\Modules\Marketplace\Models\MarketplaceItem;
use App\Models\Organization;
use App\Models\Script;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('surface.scripts', 'surface.marketplace');

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

test('member can apply script to server as saved command', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'prod-web-01',
    ]);

    $script = Script::factory()->forOrganization($org, $user)->create([
        'name' => 'Restart queue',
        'content' => 'sudo supervisorctl restart all',
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openApplyModal', (string) $script->id)
        ->set('applyServerId', (string) $server->id)
        ->call('confirmApplyToServer')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('server_recipes', [
        'server_id' => $server->id,
        'name' => 'Restart queue',
        'script' => 'sudo supervisorctl restart all',
    ]);
});

test('cloning a script preset from the marketplace creates a marketplace sourced script', function () {
    $user = ownerWithOrg();
    $org = $user->currentOrganization();

    $item = MarketplaceItem::factory()->create([
        'slug' => 'script-disk-usage-summary',
        'category' => MarketplaceItem::CATEGORY_SCRIPTS,
        'recipe_type' => MarketplaceItem::RECIPE_SCRIPT,
        'payload' => ['preset_key' => 'disk-usage-summary'],
    ]);

    Livewire::actingAs($user)
        ->test(MarketplaceIndex::class)
        ->call('cloneScriptPreset', $item->id)
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('scripts', [
        'organization_id' => $org->id,
        'source' => Script::SOURCE_MARKETPLACE,
        'marketplace_key' => 'disk-usage-summary',
    ]);
});

test('the old script presets url redirects into the marketplace catalog', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get('/scripts/marketplace')
        ->assertRedirect('/marketplace?category=scripts');
});
