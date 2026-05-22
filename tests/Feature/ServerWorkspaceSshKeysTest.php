<?php

namespace Tests\Feature;

use App\Jobs\PreviewDriftJob;
use App\Jobs\SyncAuthorizedKeysJob;
use App\Livewire\Servers\WorkspaceSshKeys;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\User;
use App\Models\UserSshKey;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
            ->assertSee('Add SSH key')
            ->assertSee('Keys on this server')
            ->assertSee('Activity')
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

        // The dedicated reminder banner was retired; the workspace now
        // exposes the profile-key affordance via the standard "Add SSH key"
        // panel + a Profile key dropdown. Assert those are still surfaced.
        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->assertSee('Add SSH key')
            ->assertSee('From profile');
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

        // Pre-seed a sibling login-user key so the new "last login key" guard doesn't
        // block the delete we're testing — this test is about the audit row, not lockout.
        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'sibling',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('s', 43).' sibling',
            'target_linux_user' => '',
        ]);

        $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('d', 43).' delete-test';

        $lw = Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('new_auth_name', 'Temp')
            ->set('new_auth_key', $pub)
            ->set('new_target_linux_user', 'root')
            ->call('addAuthorizedKey')
            ->assertHasNoErrors();

        $keyId = $server->fresh()->authorizedKeys()->where('name', 'Temp')->firstOrFail()->id;

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

    public function test_sync_dispatches_queued_job_and_marks_meta_state(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('syncAuthorizedKeys')
            ->assertHasNoErrors();

        Queue::assertPushed(SyncAuthorizedKeysJob::class, fn ($job) => $job->serverId === $server->id);

        $meta = $server->fresh()->meta ?? [];
        $this->assertSame('queued', data_get($meta, config('server_ssh_keys.meta_sync_status_key')));
        $this->assertNotEmpty(data_get($meta, config('server_ssh_keys.meta_sync_run_id_key')));
        $this->assertNotEmpty(data_get($meta, config('server_ssh_keys.meta_sync_started_at_key')));
    }

    public function test_sync_no_ops_when_a_run_is_already_in_flight(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_sync_status_key') => 'running',
            config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
        ])]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('syncAuthorizedKeys');

        Queue::assertNotPushed(SyncAuthorizedKeysJob::class);
    }

    public function test_request_sync_dispatches_job_when_set_has_a_key_for_login_user(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'login-user-key',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('a', 43).' login',
            'target_linux_user' => '',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('requestSyncAuthorizedKeys')
            ->assertHasNoErrors()
            ->assertSet('showConfirmActionModal', false);

        Queue::assertPushed(SyncAuthorizedKeysJob::class);
    }

    public function test_dismiss_sync_banner_clears_meta_when_not_running(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_sync_status_key') => 'completed',
            config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
            config('server_ssh_keys.meta_sync_finished_at_key') => now()->toIso8601String(),
        ])]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('dismissSyncBanner');

        $meta = $server->fresh()->meta ?? [];
        $this->assertNull(data_get($meta, config('server_ssh_keys.meta_sync_status_key')));
        $this->assertNull(data_get($meta, config('server_ssh_keys.meta_sync_run_id_key')));
    }

    public function test_dismiss_sync_banner_is_noop_while_running(): void
    {
        // Mid-run dismissals would just hide a banner the operator probably wants to read.
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_sync_status_key') => 'running',
            config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
        ])]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('dismissSyncBanner');

        $this->assertSame('running', data_get($server->fresh()->meta ?? [], config('server_ssh_keys.meta_sync_status_key')));
    }

    public function test_request_sync_is_blocked_while_another_run_is_in_flight(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_sync_status_key') => 'running',
            config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
        ])]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('requestSyncAuthorizedKeys')
            ->assertSet('showConfirmActionModal', false);

        Queue::assertNotPushed(SyncAuthorizedKeysJob::class);
    }

    public function test_preview_diff_dispatches_queued_job_and_marks_meta(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('previewDiff')
            ->assertHasNoErrors();

        Queue::assertPushed(PreviewDriftJob::class, fn ($job) => $job->serverId === $server->id);

        $meta = $server->fresh()->meta ?? [];
        $this->assertSame('queued', data_get($meta, config('server_ssh_keys.meta_drift_status_key')));
        $this->assertNotEmpty(data_get($meta, config('server_ssh_keys.meta_drift_run_id_key')));
    }

    public function test_preview_diff_no_ops_when_drift_run_already_in_flight(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_drift_status_key') => 'running',
            config('server_ssh_keys.meta_drift_run_id_key') => '01ABC',
        ])]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('previewDiff');

        Queue::assertNotPushed(PreviewDriftJob::class);
    }

    public function test_poll_sync_status_hydrates_diff_result_from_drift_cache(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $runId = '01DRIFTHYDRATE0000000';
        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_drift_status_key') => 'completed',
            config('server_ssh_keys.meta_drift_run_id_key') => $runId,
        ])]);

        $cacheKey = (string) config('server_ssh_keys.drift_output_cache_key_prefix', 'ssh_key_drift_output:').$runId;
        \Illuminate\Support\Facades\Cache::put($cacheKey, [
            'lines' => ['> Connecting…', '> Done. Diff computed.'],
            'diff_result' => [
                'root' => ['remote' => [], 'desired' => [], 'added' => [], 'removed' => []],
            ],
        ], 60);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('pollSyncStatus');

        $this->assertNotNull($component->get('diff_result'));
        $this->assertArrayHasKey('root', $component->get('diff_result'));
        $this->assertContains('> Done. Diff computed.', $component->get('diff_output'));
    }

    public function test_delete_blocks_last_login_user_key(): void
    {
        // Single key targeting the login user (empty target_linux_user means "login user")
        // — deletion would lock Dply out, must be refused.
        [$user, $server] = $this->actingOwnerWithServer();

        $key = ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'last-login-key',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('e', 43).' last',
            'target_linux_user' => '',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('deleteAuthorizedKey', $key->id);

        $this->assertNotNull($key->fresh(), 'Last login-user key must not be deleted.');
        $this->assertDatabaseMissing('server_ssh_key_audit_events', [
            'server_id' => $server->id,
            'event' => ServerSshKeyAuditEvent::EVENT_KEY_DELETED,
        ]);
    }

    public function test_delete_allows_login_user_key_when_another_login_key_exists(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $kept = ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'kept',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('f', 43).' kept',
            'target_linux_user' => '',
        ]);
        $extra = ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'extra',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('g', 43).' extra',
            'target_linux_user' => 'root',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('deleteAuthorizedKey', $extra->id)
            ->assertHasNoErrors();

        $this->assertNull($extra->fresh(), 'Non-last login-user key should delete.');
        $this->assertNotNull($kept->fresh());
    }

    public function test_poll_clears_stale_drift_when_sync_completes(): void
    {
        // Operator: ran drift preview → saw drift → clicked Sync → sync completed.
        // The diff_result on screen is now stale (the file matches the panel after sync).
        // pollSyncStatus must clear it so the user doesn't think drift still exists.
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_sync_status_key') => 'completed',
            config('server_ssh_keys.meta_sync_run_id_key') => '01SYNCDONE',
            config('server_ssh_keys.meta_sync_finished_at_key') => now()->toIso8601String(),
        ])]);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('diff_result', ['root' => ['remote' => [], 'desired' => [], 'added' => [], 'removed' => [], 'kept' => []]])
            ->set('diff_output', ['> stale transcript'])
            ->call('pollSyncStatus');

        $this->assertNull($component->get('diff_result'));
        $this->assertSame([], $component->get('diff_output'));
    }

    public function test_dismiss_drift_banner_clears_transcript_but_keeps_diff_result(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('diff_output', ['> Connecting…', '> Done.'])
            ->set('diff_result', ['root' => ['remote' => [], 'desired' => [], 'added' => [], 'removed' => []]])
            ->call('dismissDriftBanner')
            ->assertSet('diff_output', [])
            ->assertNotSet('diff_result', null);
    }

    public function test_preview_diff_is_blocked_while_sync_is_in_flight(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_sync_status_key') => 'running',
            config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
        ])]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('previewDiff');

        // No drift job dispatched: sync busy guard short-circuits before the dispatch.
        Queue::assertNotPushed(PreviewDriftJob::class);
    }

    public function test_deploy_org_key_is_blocked_while_sync_is_in_flight(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $server->update(['meta' => array_merge($server->meta ?? [], [
            config('server_ssh_keys.meta_sync_status_key') => 'running',
            config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
        ])]);

        $this->mock(\App\Services\Servers\OrganizationTeamSshKeyServerDeployer::class, function ($mock): void {
            $mock->shouldNotReceive('deployOrganizationKey');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('deploy_org_key_id', 'whatever')
            ->call('deployOrganizationKey')
            ->assertHasNoErrors();
    }

    public function test_request_sync_opens_confirm_modal_when_key_set_is_empty(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        // The synchronizer must NOT be invoked — the confirm modal should gate the call.
        $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock) {
            $mock->shouldNotReceive('sync');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('requestSyncAuthorizedKeys')
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'syncAuthorizedKeys')
            ->assertSet('confirmActionModalDestructive', true);
    }

    public function test_request_sync_opens_confirm_modal_when_no_key_targets_login_user(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        // A key exists but it targets a non-login system user — Dply itself loses access on sync.
        ServerAuthorizedKey::query()->create([
            'server_id' => $server->id,
            'name' => 'deploy-only-key',
            'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('c', 43).' deploy',
            'target_linux_user' => 'deploy',
        ]);

        $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock) {
            $mock->shouldNotReceive('sync');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->call('requestSyncAuthorizedKeys')
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'syncAuthorizedKeys');
    }

    public function test_request_save_advanced_runs_save_directly_when_disable_toggle_unchanged(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('advanced_disable_sync', false)
            ->set('advanced_label_template', '{name} · {hostname}')
            ->call('requestSaveAdvancedSettings')
            ->assertHasNoErrors()
            ->assertSet('showConfirmActionModal', false);

        $this->assertSame(
            '{name} · {hostname}',
            data_get($server->fresh()->meta ?? [], config('server_ssh_keys.meta_label_template_key')),
        );
    }

    public function test_request_save_advanced_opens_confirm_modal_when_disable_toggle_flips_on(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('advanced_disable_sync', true)
            ->call('requestSaveAdvancedSettings')
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'saveAdvancedSettings')
            ->assertSet('confirmActionModalDestructive', true);

        // Save MUST NOT have been committed before the confirmation.
        $this->assertNotTrue(
            (bool) data_get($server->fresh()->meta ?? [], config('server_ssh_keys.meta_disable_sync_key')),
        );
    }

    public function test_request_save_advanced_skips_confirm_when_toggle_already_disabled(): void
    {
        // Going from on → off (re-enabling sync) is the safe direction; no confirm needed.
        [$user, $server] = $this->actingOwnerWithServer();
        $server->update(['meta' => [
            config('server_ssh_keys.meta_disable_sync_key') => true,
        ]]);

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('advanced_disable_sync', false)
            ->call('requestSaveAdvancedSettings')
            ->assertHasNoErrors()
            ->assertSet('showConfirmActionModal', false);

        $this->assertFalse(
            (bool) data_get($server->fresh()->meta ?? [], config('server_ssh_keys.meta_disable_sync_key')),
        );
    }

    public function test_add_authorized_key_emits_panel_banner_event(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('p', 43).' panel-banner-test';

        $component = Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('new_auth_name', 'Panel banner key')
            ->set('new_auth_key', $pub)
            ->set('new_target_linux_user', 'root')
            ->call('addAuthorizedKey')
            ->assertHasNoErrors();

        $this->assertNotEmpty($component->get('panel_event_lines'));
        $this->assertSame(__('Key added — sync to apply'), $component->get('panel_event_message'));

        $lines = $component->get('panel_event_lines');
        $this->assertTrue(collect($lines)->contains(fn ($l) => str_contains($l, 'Sync now')));
    }

    public function test_dismiss_panel_banner_clears_state(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('panel_event_lines', ['> Added "Foo" to the panel.'])
            ->set('panel_event_message', 'Key added — sync to apply')
            ->call('dismissPanelBanner')
            ->assertSet('panel_event_lines', [])
            ->assertSet('panel_event_message', '');
    }

    public function test_add_authorized_key_dispatches_close_modal_on_success(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('d', 43).' modal-close-test';

        Livewire::actingAs($user)
            ->test(WorkspaceSshKeys::class, ['server' => $server])
            ->set('new_auth_name', 'Modal close')
            ->set('new_auth_key', $pub)
            ->set('new_target_linux_user', 'root')
            ->call('addAuthorizedKey')
            ->assertHasNoErrors()
            ->assertDispatched('close-modal', 'add-ssh-key-modal');
    }
}
