<?php

namespace Tests\Feature;

use App\Livewire\Settings\SshKeys;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
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
