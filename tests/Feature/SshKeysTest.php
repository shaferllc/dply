<?php

namespace Tests\Feature;

use App\Livewire\Profile\PersonalSshKeyModal;
use App\Livewire\Settings\SshKeys;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Models\UserSshKey;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SshKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_ssh_keys_page_is_reachable_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.ssh-keys'))
            ->assertOk();
    }

    public function test_ssh_keys_page_can_show_server_create_onboarding_help(): void
    {
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
    }

    public function test_ssh_keys_page_uses_shared_add_key_modal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('profile.ssh-keys'))
            ->assertOk()
            ->assertSee('Add SSH key')
            ->assertSee('Add a personal SSH key')
            ->assertSee('Generate key pair');
    }

    public function test_personal_modal_generate_key_pair_prefills_public_and_dispatches_profile_event(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 generation.');
        }

        $user = $this->userWithOrganization();

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

        $this->assertSame(
            0,
            UserSshKey::query()->where('user_id', $user->id)->count(),
            'Generating a key pair must not persist a profile key until the user saves.'
        );
    }

    public function test_user_can_create_ssh_key_without_deploy(): void
    {
        $user = $this->userWithOrganization();

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
    }

    public function test_user_can_create_ssh_key_from_shared_modal(): void
    {
        $user = $this->userWithOrganization();

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
    }

    public function test_invalid_public_key_is_rejected(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(SshKeys::class)
            ->set('new_name', 'Bad')
            ->set('new_public_key', 'not-a-real-key')
            ->call('createKey')
            ->assertHasErrors(['new_public_key']);
    }

    public function test_deploy_to_server_calls_synchronizer(): void
    {
        $user = $this->userWithOrganization();
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
    }
}
