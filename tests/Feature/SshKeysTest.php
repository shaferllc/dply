<?php

namespace Tests\Feature\SshKeysTest;

use App\Livewire\Profile\PersonalSshKeyModal;
use App\Livewire\Settings\SshKeys;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Models\UserSshKey;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('ssh keys page is reachable for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.ssh-keys'))
        ->assertOk();
});

test('ssh keys page can show server create onboarding help', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.ssh-keys', [
            'source' => 'servers.create',
            'return_to' => 'servers.create',
        ]))
        ->assertOk()
        ->assertSee('Add at least one SSH key to your profile first')
        ->assertSee('Back to create BYO server')
        ->assertSee('ssh-keygen -t ed25519 -C "you@example.com"');
});

test('ssh keys page uses shared add key modal', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.ssh-keys'))
        ->assertOk()
        ->assertSee('Add SSH key')
        ->assertSee('Add a personal SSH key')
        ->assertSee('Generate key pair');
});

test('personal modal generate key pair prefills public and dispatches profile event', function () {
    if (! function_exists('sodium_crypto_sign_keypair')) {
        $this->markTestSkipped('sodium extension required for Ed25519 generation.');
    }

    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(PersonalSshKeyModal::class, ['source' => 'servers.create'])
        ->call('generateKeyPair')
        ->assertHasNoErrors()
        ->assertSet('public_key', fn ($v) => is_string($v) && str_starts_with($v, 'ssh-ed25519'))
        ->assertSet('name', __('Generated key'))
        ->assertDispatched('dply-ssh-profile-keypair-generated', function ($name, $params) {
            return isset($params['privateKey'], $params['publicKey'])
                && str_contains((string) $params['privateKey'], 'BEGIN OPENSSH PRIVATE KEY')
                && str_starts_with((string) $params['publicKey'], 'ssh-ed25519');
        });

    expect(UserSshKey::query()->where('user_id', $user->id)->count())->toBe(0, 'Generating a key pair must not persist a profile key until the user saves.');
});

test('user can create ssh key without deploy', function () {
    $user = userWithOrganization();

    $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('b', 43).' integration';

    Livewire::actingAs($user)
        ->test(SshKeys::class)
        ->set('new_name', 'Laptop')
        ->set('new_public_key', $pub)
        ->set('new_provision_on_new_servers', true)
        ->call('createKey')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('user_ssh_keys', [
        'user_id' => $user->id,
        'name' => 'Laptop',
        'provision_on_new_servers' => true,
    ]);
});

test('user can create ssh key from shared modal', function () {
    $user = userWithOrganization();

    $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('k', 43).' modal';

    Livewire::actingAs($user)
        ->test(PersonalSshKeyModal::class, ['source' => 'servers.create'])
        ->set('name', 'Modal laptop')
        ->set('public_key', $pub)
        ->set('provision_on_new_servers', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('user_ssh_keys', [
        'user_id' => $user->id,
        'name' => 'Modal laptop',
        'provision_on_new_servers' => true,
    ]);
});

test('invalid public key is rejected', function () {
    $user = userWithOrganization();

    Livewire::actingAs($user)
        ->test(SshKeys::class)
        ->set('new_name', 'Bad')
        ->set('new_public_key', 'not-a-real-key')
        ->call('createKey')
        ->assertHasErrors(['new_public_key']);
});

test('deploy to server calls synchronizer', function () {
    $user = userWithOrganization();
    $org = $user->currentOrganization();

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock) {
        $mock->shouldReceive('sync')->once()->andReturn('DPLY_AUTH_EXIT:0');
    });

    $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('c', 43).' deploy';

    Livewire::actingAs($user)
        ->test(SshKeys::class)
        ->set('new_name', 'Deploy key')
        ->set('new_public_key', $pub)
        ->set('new_server_ids', [(string) $server->id])
        ->call('createKey')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('server_authorized_keys', [
        'server_id' => $server->id,
    ]);
});
