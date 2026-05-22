<?php

namespace Tests\Feature\ServerWorkspaceSshKeysTest;

use App\Jobs\PreviewDriftJob;
use App\Jobs\SyncAuthorizedKeysJob;
use App\Livewire\Servers\WorkspaceSshKeys;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\User;
use App\Models\UserSshKey;
use App\Services\Servers\OrganizationTeamSshKeyServerDeployer;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingOwnerWithServer(): array
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

test('add key writes audit event', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('component renders simplified sections', function () {
    [$user, $server] = actingOwnerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->assertSee('Add SSH key')
        ->assertSee('Keys on this server')
        ->assertSee('Activity')
        ->assertDontSee('Bulk import')
        ->assertDontSee('Export CSV')
        ->assertDontSee('Export audit CSV')
        ->assertSee('Generate key pair');
});

test('generate key pair prefills public and dispatches browser event', function () {
    if (! function_exists('sodium_crypto_sign_keypair')) {
        $this->markTestSkipped('sodium extension required for Ed25519 generation.');
    }

    [$user, $server] = actingOwnerWithServer();

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

    expect(ServerAuthorizedKey::query()->where('server_id', $server->id)->count())->toBe(0, 'Generating a key pair must not persist an authorized key row until the user adds it.');

    expect(UserSshKey::query()->where('user_id', $user->id)->count())->toBe(0, 'Generating a key pair must not save to profile keys automatically.');
});

test('component reminds user when server has no personal profile key attached', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('component uses shared modal when no profile keys exist', function () {
    [$user, $server] = actingOwnerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->assertSee('Add profile key')
        ->assertSee('Add a personal SSH key');
});

test('component hides reminder when server has current users personal key attached', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('component hides reminder when matching profile key was added manually', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('delete key writes audit event', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('invalid public key is rejected', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('review date update persists and writes audit event', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('sync dispatches queued job and marks meta state', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('syncAuthorizedKeys')
        ->assertHasNoErrors();

    Queue::assertPushed(SyncAuthorizedKeysJob::class, fn ($job) => $job->serverId === $server->id);

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_ssh_keys.meta_sync_status_key')))->toBe('queued');
    expect(data_get($meta, config('server_ssh_keys.meta_sync_run_id_key')))->not->toBeEmpty();
    expect(data_get($meta, config('server_ssh_keys.meta_sync_started_at_key')))->not->toBeEmpty();
});

test('sync no ops when a run is already in flight', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_sync_status_key') => 'running',
        config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
    ])]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('syncAuthorizedKeys');

    Queue::assertNotPushed(SyncAuthorizedKeysJob::class);
});

test('request sync dispatches job when set has a key for login user', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

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
});

test('dismiss sync banner clears meta when not running', function () {
    [$user, $server] = actingOwnerWithServer();

    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_sync_status_key') => 'completed',
        config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
        config('server_ssh_keys.meta_sync_finished_at_key') => now()->toIso8601String(),
    ])]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('dismissSyncBanner');

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_ssh_keys.meta_sync_status_key')))->toBeNull();
    expect(data_get($meta, config('server_ssh_keys.meta_sync_run_id_key')))->toBeNull();
});

test('dismiss sync banner is noop while running', function () {
    // Mid-run dismissals would just hide a banner the operator probably wants to read.
    [$user, $server] = actingOwnerWithServer();

    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_sync_status_key') => 'running',
        config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
    ])]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('dismissSyncBanner');

    expect(data_get($server->fresh()->meta ?? [], config('server_ssh_keys.meta_sync_status_key')))->toBe('running');
});

test('request sync is blocked while another run is in flight', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_sync_status_key') => 'running',
        config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
    ])]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('requestSyncAuthorizedKeys')
        ->assertSet('showConfirmActionModal', false);

    Queue::assertNotPushed(SyncAuthorizedKeysJob::class);
});

test('preview diff dispatches queued job and marks meta', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('previewDiff')
        ->assertHasNoErrors();

    Queue::assertPushed(PreviewDriftJob::class, fn ($job) => $job->serverId === $server->id);

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_ssh_keys.meta_drift_status_key')))->toBe('queued');
    expect(data_get($meta, config('server_ssh_keys.meta_drift_run_id_key')))->not->toBeEmpty();
});

test('preview diff no ops when drift run already in flight', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_drift_status_key') => 'running',
        config('server_ssh_keys.meta_drift_run_id_key') => '01ABC',
    ])]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('previewDiff');

    Queue::assertNotPushed(PreviewDriftJob::class);
});

test('poll sync status hydrates diff result from drift cache', function () {
    [$user, $server] = actingOwnerWithServer();

    $runId = '01DRIFTHYDRATE0000000';
    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_drift_status_key') => 'completed',
        config('server_ssh_keys.meta_drift_run_id_key') => $runId,
    ])]);

    $cacheKey = (string) config('server_ssh_keys.drift_output_cache_key_prefix', 'ssh_key_drift_output:').$runId;
    Cache::put($cacheKey, [
        'lines' => ['> Connecting…', '> Done. Diff computed.'],
        'diff_result' => [
            'root' => ['remote' => [], 'desired' => [], 'added' => [], 'removed' => []],
        ],
    ], 60);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('pollSyncStatus');

    expect($component->get('diff_result'))->not->toBeNull();
    expect($component->get('diff_result'))->toHaveKey('root');
    expect($component->get('diff_output'))->toContain('> Done. Diff computed.');
});

test('delete blocks last login user key', function () {
    // Single key targeting the login user (empty target_linux_user means "login user")
    // — deletion would lock Dply out, must be refused.
    [$user, $server] = actingOwnerWithServer();

    $key = ServerAuthorizedKey::query()->create([
        'server_id' => $server->id,
        'name' => 'last-login-key',
        'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('e', 43).' last',
        'target_linux_user' => '',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('deleteAuthorizedKey', $key->id);

    expect($key->fresh())->not->toBeNull('Last login-user key must not be deleted.');
    $this->assertDatabaseMissing('server_ssh_key_audit_events', [
        'server_id' => $server->id,
        'event' => ServerSshKeyAuditEvent::EVENT_KEY_DELETED,
    ]);
});

test('delete allows login user key when another login key exists', function () {
    [$user, $server] = actingOwnerWithServer();

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

    expect($extra->fresh())->toBeNull('Non-last login-user key should delete.');
    expect($kept->fresh())->not->toBeNull();
});

test('poll clears stale drift when sync completes', function () {
    // Operator: ran drift preview → saw drift → clicked Sync → sync completed.
    // The diff_result on screen is now stale (the file matches the panel after sync).
    // pollSyncStatus must clear it so the user doesn't think drift still exists.
    [$user, $server] = actingOwnerWithServer();

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

    expect($component->get('diff_result'))->toBeNull();
    expect($component->get('diff_output'))->toBe([]);
});

test('dismiss drift banner clears transcript but keeps diff result', function () {
    [$user, $server] = actingOwnerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->set('diff_output', ['> Connecting…', '> Done.'])
        ->set('diff_result', ['root' => ['remote' => [], 'desired' => [], 'added' => [], 'removed' => []]])
        ->call('dismissDriftBanner')
        ->assertSet('diff_output', [])
        ->assertNotSet('diff_result', null);
});

test('preview diff is blocked while sync is in flight', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_sync_status_key') => 'running',
        config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
    ])]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->call('previewDiff');

    // No drift job dispatched: sync busy guard short-circuits before the dispatch.
    Queue::assertNotPushed(PreviewDriftJob::class);
});

test('deploy org key is blocked while sync is in flight', function () {
    [$user, $server] = actingOwnerWithServer();

    $server->update(['meta' => array_merge($server->meta ?? [], [
        config('server_ssh_keys.meta_sync_status_key') => 'running',
        config('server_ssh_keys.meta_sync_run_id_key') => '01ABC',
    ])]);

    $this->mock(OrganizationTeamSshKeyServerDeployer::class, function ($mock): void {
        $mock->shouldNotReceive('deployOrganizationKey');
    });

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->set('deploy_org_key_id', 'whatever')
        ->call('deployOrganizationKey')
        ->assertHasNoErrors();
});

test('request sync opens confirm modal when key set is empty', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('request sync opens confirm modal when no key targets login user', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('request save advanced runs save directly when disable toggle unchanged', function () {
    [$user, $server] = actingOwnerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->set('advanced_disable_sync', false)
        ->set('advanced_label_template', '{name} · {hostname}')
        ->call('requestSaveAdvancedSettings')
        ->assertHasNoErrors()
        ->assertSet('showConfirmActionModal', false);

    expect(data_get($server->fresh()->meta ?? [], config('server_ssh_keys.meta_label_template_key')))->toBe('{name} · {hostname}');
});

test('request save advanced opens confirm modal when disable toggle flips on', function () {
    [$user, $server] = actingOwnerWithServer();

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
});

test('request save advanced skips confirm when toggle already disabled', function () {
    // Going from on → off (re-enabling sync) is the safe direction; no confirm needed.
    [$user, $server] = actingOwnerWithServer();
    $server->update(['meta' => [
        config('server_ssh_keys.meta_disable_sync_key') => true,
    ]]);

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->set('advanced_disable_sync', false)
        ->call('requestSaveAdvancedSettings')
        ->assertHasNoErrors()
        ->assertSet('showConfirmActionModal', false);

    expect((bool) data_get($server->fresh()->meta ?? [], config('server_ssh_keys.meta_disable_sync_key')))->toBeFalse();
});

test('add authorized key emits panel banner event', function () {
    [$user, $server] = actingOwnerWithServer();

    $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('p', 43).' panel-banner-test';

    $component = Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->set('new_auth_name', 'Panel banner key')
        ->set('new_auth_key', $pub)
        ->set('new_target_linux_user', 'root')
        ->call('addAuthorizedKey')
        ->assertHasNoErrors();

    expect($component->get('panel_event_lines'))->not->toBeEmpty();
    expect($component->get('panel_event_message'))->toBe(__('Key added — sync to apply'));

    $lines = $component->get('panel_event_lines');
    expect(collect($lines)->contains(fn ($l) => str_contains($l, 'Sync now')))->toBeTrue();
});

test('dismiss panel banner clears state', function () {
    [$user, $server] = actingOwnerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->set('panel_event_lines', ['> Added "Foo" to the panel.'])
        ->set('panel_event_message', 'Key added — sync to apply')
        ->call('dismissPanelBanner')
        ->assertSet('panel_event_lines', [])
        ->assertSet('panel_event_message', '');
});

test('add authorized key dispatches close modal on success', function () {
    [$user, $server] = actingOwnerWithServer();

    $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'.str_repeat('d', 43).' modal-close-test';

    Livewire::actingAs($user)
        ->test(WorkspaceSshKeys::class, ['server' => $server])
        ->set('new_auth_name', 'Modal close')
        ->set('new_auth_key', $pub)
        ->set('new_target_linux_user', 'root')
        ->call('addAuthorizedKey')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal', 'add-ssh-key-modal');
});
