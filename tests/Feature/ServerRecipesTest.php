<?php


namespace Tests\Feature\ServerRecipesTest;
use App\Livewire\Servers\WorkspaceRun;
use App\Models\Organization;
use App\Models\Script;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('run page explains boundaries', function () {
    $user = userWithOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    // The page used to be /recipes — it's now /run, the merged
    // surface for executing things on the server. The "Server-level
    // commands" banner above the cards explicitly redirects users
    // who were looking for site deploys.
    $this->actingAs($user)
        ->get(route('servers.run', $server))
        ->assertOk()
        ->assertSee('Server-level commands')
        ->assertSee('Browse library')
        ->assertSee('Where else commands live')
        ->assertSee('Run a one-off command')
        ->assertSee('Open scripts');
});

test('user can copy organization script to server saved commands', function () {
    $user = userWithOrganization();
    $organization = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $organization?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $script = Script::query()->create([
        'organization_id' => $organization?->id,
        'user_id' => $user->id,
        'name' => 'Queue restart',
        'content' => "php artisan queue:restart\n",
        'source' => Script::SOURCE_USER_CREATED,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceRun::class, ['server' => $server])
        ->call('setLibraryTab', 'organization')
        ->call('saveOrganizationScriptToServer', (string) $script->id);

    $this->assertDatabaseHas('server_recipes', [
        'server_id' => $server->id,
        'name' => 'Queue restart',
    ]);
});

test('user can save marketplace preset to server', function () {
    $user = userWithOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $presets = config('script_marketplace');
    $key = array_key_first($presets);
    $name = $presets[$key]['name'];

    Livewire::actingAs($user)
        ->test(WorkspaceRun::class, ['server' => $server])
        ->call('saveMarketplacePresetToServer', $key);

    $this->assertDatabaseHas('server_recipes', [
        'server_id' => $server->id,
        'name' => $name,
    ]);
});

test('user can edit existing saved command', function () {
    $user = userWithOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $recipe = ServerRecipe::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Original name',
        'script' => 'uptime',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceRun::class, ['server' => $server])
        ->call('editRecipe', (string) $recipe->id)
        ->set('new_recipe_name', 'Updated name')
        ->set('new_recipe_script', 'whoami')
        ->call('addRecipe');

    $this->assertDatabaseHas('server_recipes', [
        'id' => $recipe->id,
        'name' => 'Updated name',
        'script' => 'whoami',
    ]);
});