<?php

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceSshKeys;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServerWorkspaceSshKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function actingOwnerWithServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        return [$user, $server];
    }

    public function test_add_key_writes_audit_event(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('b', 43).' audit-test';

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('new_auth_name', 'Work laptop')
            ->set('new_auth_key', $pub)
            ->set('new_target_linux_user', 'root')
            ->call('addAuthorizedKey')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_ssh_key_audit_events', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'event' => ServerSshKeyAuditEvent::EVENT_KEY_CREATED,
        ]);
    }

    public function test_delete_key_writes_audit_event(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('d', 43).' delete-test';

        $lw = Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('new_auth_name', 'Temp')
            ->set('new_auth_key', $pub)
            ->set('new_target_linux_user', 'root')
            ->call('addAuthorizedKey')
            ->assertHasNoErrors();

        $keyId = $server->fresh()->authorizedKeys()->firstOrFail()->id;

        $lw->call('deleteAuthorizedKey', $keyId)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_ssh_key_audit_events', [
            'server_id' => $server->id,
            'event' => ServerSshKeyAuditEvent::EVENT_KEY_DELETED,
        ]);
    }
}
