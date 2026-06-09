<?php

use App\Livewire\Credentials\AddProviderCredentialModal;
use App\Livewire\Servers\ImportFromDigitalOcean;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function importFlowUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('import from digitalocean page offers inline connect modal when no credentials exist', function () {
    $user = importFlowUser();

    $this->actingAs($user)
        ->get(route('servers.import.digitalocean'))
        ->assertOk()
        ->assertSee('Connect DigitalOcean first')
        ->assertSee('add-provider-credential-modal', false);
});

test('import from digitalocean page lists saved credentials and add account link', function () {
    $user = importFlowUser();
    $org = $user->currentOrganization();

    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Production DO',
    ]);

    $this->actingAs($user)
        ->get(route('servers.import.digitalocean'))
        ->assertOk()
        ->assertSee('Production DO')
        ->assertSee('Add account');
});

test('add provider credential modal opens with requested provider', function () {
    config(['server_providers.enabled.hetzner' => true]);

    $user = importFlowUser();

    Livewire::actingAs($user)
        ->test(AddProviderCredentialModal::class)
        ->call('openModal', 'hetzner')
        ->assertSet('active_provider', 'hetzner');
});

test('import flow selects newly stored digitalocean credential', function () {
    $user = importFlowUser();
    $org = $user->currentOrganization();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Recovery DO',
    ]);

    Livewire::actingAs($user)
        ->test(ImportFromDigitalOcean::class)
        ->dispatch('provider-credential-created', provider: 'digitalocean', credentialId: $credential->id)
        ->assertSet('credentialId', (string) $credential->id);
});
