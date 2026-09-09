<?php

use App\Livewire\Servers\ImportFromDigitalOcean;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Organization} */
function importPageUserWithRole(string $role): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

/**
 * Flip a live member down to deployer. The pivot write alone is not enough:
 * both the org's cross-instance role memo and the user's currentOrganization()
 * memo would keep serving the old role for the rest of the process.
 */
function demoteToDeployer(User $user, Organization $org): void
{
    $org->users()->updateExistingPivot($user->id, ['role' => 'deployer']);
    Organization::flushMemberRoleCache();
    $user->flushCurrentOrganizationCache();
}

test('deployer cannot open the digitalocean import page', function () {
    [$user] = importPageUserWithRole('deployer');

    $this->actingAs($user)
        ->get(route('servers.import.digitalocean'))
        ->assertForbidden();
});

test('owner can open the digitalocean import page', function () {
    [$user] = importPageUserWithRole('owner');

    $this->actingAs($user)
        ->get(route('servers.import.digitalocean'))
        ->assertOk();
});

test('deployer cannot scan droplets on a hydrated import page', function () {
    [$owner, $org] = importPageUserWithRole('owner');

    ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Production DO',
    ]);

    // The page mounts for the owner, then the role is demoted underneath the
    // live component — scan() must refuse rather than spend the org's token.
    $component = Livewire::actingAs($owner)->test(ImportFromDigitalOcean::class);

    demoteToDeployer($owner, $org);

    $component->call('scan')->assertForbidden();
});

test('deployer cannot adopt a droplet on a hydrated import page', function () {
    [$owner, $org] = importPageUserWithRole('owner');

    $credential = ProviderCredential::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Production DO',
    ]);

    $component = Livewire::actingAs($owner)
        ->test(ImportFromDigitalOcean::class)
        ->set('credentialId', (string) $credential->id)
        ->set('droplets', [[
            'id' => 12345,
            'name' => 'web-1',
            '_public_ipv4' => '203.0.113.10',
            '_already_imported' => false,
        ]])
        ->call('openAdoptModal', 12345)
        ->set('adoptKeySource', 'generate');

    demoteToDeployer($owner, $org);

    $component->call('adopt')->assertForbidden();

    expect(Server::query()->count())->toBe(0);
});
