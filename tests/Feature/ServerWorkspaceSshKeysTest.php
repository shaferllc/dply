<?php

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceSshKeys;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\User;
use App\Models\UserSshKey;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
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

    public function test_component_renders_simplified_sections(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->assertSee('New SSH key')
            ->assertSee('Keys on this server')
            ->assertSee('Recent audit history')
            ->assertDontSee('Bulk import')
            ->assertDontSee('Export CSV')
            ->assertDontSee('Export audit CSV')
            ->assertSee('Generate key pair');
    }

    public function test_generate_key_pair_prefills_public_and_dispatches_browser_event(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 generation.');
        }

        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('generateNewAuthorizedKeyPair')
            ->assertHasNoErrors()
            ->assertSet('new_auth_key', fn ($v) => is_string($v) && str_starts_with($v, 'ssh-ed25519'))
            ->assertSet('new_auth_name', __('Generated key'))
            ->assertDispatched('dply-ssh-keypair-generated', function ($name, $params) {
                return isset($params['privateKey'], $params['publicKey'])
                    && str_contains((string) $params['privateKey'], 'BEGIN OPENSSH PRIVATE KEY')
                    && str_starts_with((string) $params['publicKey'], 'ssh-ed25519');
            });

        $this->assertSame(
            0,
            ServerAuthorizedKey::query()->where('server_id', $server->id)->count(),
            'Generating a key pair must not persist an authorized key row until the user adds it.'
        );

        $this->assertSame(
            0,
            UserSshKey::query()->where('user_id', $user->id)->count(),
            'Generating a key pair must not save to profile keys automatically.'
        );
    }

    public function test_component_reminds_user_when_server_has_no_personal_profile_key_attached(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        UserSshKey::factory()->create([
            'user_id' => $user->id,
            'name' => 'Work laptop',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('p', 43).' reminder-test',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->assertSee('Add one of your personal SSH keys to this server')
            ->assertSee('Select a key from your profile')
            ->assertSee('Sync authorized_keys');
    }

    public function test_component_uses_shared_modal_when_no_profile_keys_exist(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->assertSee('Add profile key')
            ->assertSee('Add a personal SSH key');
    }

    public function test_component_hides_reminder_when_server_has_current_users_personal_key_attached(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $profileKey = UserSshKey::factory()->create([
            'user_id' => $user->id,
            'name' => 'Work laptop',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('q', 43).' attached-test',
        ]);

        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'managed_key_type' => UserSshKey::class,
            'managed_key_id' => $profileKey->id,
            'name' => $profileKey->name,
            'public_key' => $profileKey->public_key,
            'target_linux_user' => '',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->assertDontSee('Add one of your personal SSH keys to this server');
    }

    public function test_component_hides_reminder_when_matching_profile_key_was_added_manually(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $profileKey = UserSshKey::factory()->create([
            'user_id' => $user->id,
            'name' => 'Work laptop',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('u', 43).' pasted-test',
        ]);

        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'managed_key_type' => null,
            'managed_key_id' => null,
            'name' => 'Imported manually',
            'public_key' => $profileKey->public_key,
            'target_linux_user' => '',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->assertDontSee('Add one of your personal SSH keys to this server');
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

    public function test_invalid_public_key_is_rejected(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('new_auth_name', 'Broken key')
            ->set('new_auth_key', 'not-a-valid-ssh-key')
            ->set('new_target_linux_user', 'root')
            ->call('addAuthorizedKey')
            ->assertHasErrors(['new_auth_key']);

        $this->assertDatabaseMissing('server_authorized_keys', [
            'server_id' => $server->id,
            'name' => 'Broken key',
        ]);
    }

    public function test_review_date_update_persists_and_writes_audit_event(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $key = ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'Existing key',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('r', 43).' review-test',
            'target_linux_user' => '',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set("reviewDates.{$key->id}", '2026-04-30')
            ->call('updateKeyReviewFromInput', $key->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_authorized_keys', [
            'id' => $key->id,
            'review_after' => '2026-04-30 00:00:00',
        ]);

        $this->assertDatabaseHas('server_ssh_key_audit_events', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'event' => ServerSshKeyAuditEvent::EVENT_KEY_UPDATED,
        ]);
    }

    public function test_sync_publickey_error_is_shown_as_friendly_message(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')
                ->once()
                ->andThrow(new \RuntimeException(
                    'Failed to execute task: App\Modules\TaskRunner\AnonymousTask: Could not create script directory: dply@159.203.33.175: Permission denied (publickey).'
                ));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('syncAuthorizedKeys')
            ->assertHasNoErrors()
            ->assertDispatched(
                'notify',
                message: "Dply could not connect to the server to sync authorized_keys. Check that the server SSH login user still accepts Dply's provisioned key. The server rejected the SSH key for root@{$server->ip_address}.",
                type: 'error'
            );
    }
}
