<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\WorkspaceSystemUsersTest;
use App\Jobs\CreateServerSystemUserJob;
use App\Jobs\DeleteOrphanSystemUsersJob;
use App\Jobs\DeleteServerSystemUserJob;
use App\Jobs\SyncServerSystemUsersJob;
use App\Livewire\Servers\WorkspaceSystemUsers;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSystemUser;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

/**
 * @return array{0: User, 1: Server}
 */
function userAndServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '127.0.0.1',
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    return [$user, $server];
}
test('renders for a server the user can view', function () {
    [$user, $server] = userAndServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->assertOk()
        ->assertSee('System users');
});
test('create dispatches create server system user job', function () {
    Bus::fake();
    config(['site_settings.vm_site_file_web_group' => 'www-data']);
    [$user, $server] = userAndServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->set('new_username', 'app-user')
        ->set('new_sudo', true)
        ->set('new_shell', '/usr/sbin/nologin')
        ->set('new_add_web_group', true)
        ->call('queueCreate')
        ->assertHasNoErrors();

    Bus::assertDispatched(
        CreateServerSystemUserJob::class,
        fn (CreateServerSystemUserJob $job): bool => $job->serverId === $server->id
            && $job->username === 'app-user'
            && $job->grantSudo === true
            && $job->shell === '/usr/sbin/nologin'
            && $job->extraGroups === ['www-data'],
    );
});
test('create omits web group when unchecked', function () {
    Bus::fake();
    config(['site_settings.vm_site_file_web_group' => 'www-data']);
    [$user, $server] = userAndServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->set('new_username', 'svc-runner')
        ->set('new_add_web_group', false)
        ->call('queueCreate')
        ->assertHasNoErrors();

    Bus::assertDispatched(
        CreateServerSystemUserJob::class,
        fn (CreateServerSystemUserJob $job): bool => $job->username === 'svc-runner'
            && $job->shell === '/bin/bash'
            && $job->extraGroups === [],
    );
});
test('create rejects unsupported shell', function () {
    Bus::fake();
    [$user, $server] = userAndServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->set('new_username', 'app-user')
        ->set('new_shell', '/bin/zsh')
        ->call('queueCreate')
        ->assertHasErrors(['new_shell']);

    Bus::assertNotDispatched(CreateServerSystemUserJob::class);
});
test('create validates username format', function () {
    Bus::fake();
    [$user, $server] = userAndServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->set('new_username', 'BadName!')
        ->call('queueCreate')
        ->assertHasErrors(['new_username']);

    Bus::assertNotDispatched(CreateServerSystemUserJob::class);
});
test('remove dispatches delete server system user job', function () {
    Bus::fake();
    [$user, $server] = userAndServer();
    seedRemote($server, 'app-user');

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('openRemoveModal', 'app-user')
        ->set('remove_confirm', 'app-user')
        ->call('queueRemove')
        ->assertHasNoErrors();

    Bus::assertDispatched(
        DeleteServerSystemUserJob::class,
        fn (DeleteServerSystemUserJob $job): bool => $job->serverId === $server->id
            && $job->username === 'app-user',
    );
});
test('remove requires confirm to match username', function () {
    Bus::fake();
    [$user, $server] = userAndServer();
    seedRemote($server, 'app-user');

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('openRemoveModal', 'app-user')
        ->set('remove_confirm', 'wrong')
        ->call('queueRemove')
        ->assertHasErrors(['remove_confirm']);

    Bus::assertNotDispatched(DeleteServerSystemUserJob::class);
});
test('open remove modal rejects unknown username', function () {
    [$user, $server] = userAndServer();
    seedRemote($server, 'app-user');

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('openRemoveModal', 'something-not-listed')
        ->assertSet('remove_username', '');
});
function seedRemote(Server $server, string $username): void
{
    ServerSystemUser::create([
        'server_id' => $server->id,
        'username' => $username,
        'uid' => 1099,
        'home' => '/home/'.$username,
        'shell' => '/bin/bash',
        'groups' => [$username],
        'last_seen_at' => now(),
    ]);
}
test('load users dispatches sync job and seeds console row', function () {
    Bus::fake();
    [$user, $server] = userAndServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('loadUsers')
        ->assertHasNoErrors();

    Bus::assertDispatched(
        SyncServerSystemUsersJob::class,
        fn (SyncServerSystemUsersJob $job): bool => $job->serverId === $server->id
            && $job->userId === $user->id,
    );

    expect(ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->where('kind', 'system_user')
        ->where('status', ConsoleAction::STATUS_QUEUED)
        ->count())->toBe(1);
});
test('create seeds a queued console row', function () {
    Bus::fake();
    [$user, $server] = userAndServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->set('new_username', 'app-user')
        ->call('queueCreate')
        ->assertHasNoErrors();

    Bus::assertDispatched(CreateServerSystemUserJob::class);

    $row = ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->where('kind', 'system_user')
        ->where('status', ConsoleAction::STATUS_QUEUED)
        ->first();

    expect($row)->not->toBeNull();
    $this->assertStringContainsString('app-user', (string) $row->label);
});
test('queue remove orphans dispatches bulk job with orphans only', function () {
    Bus::fake();
    [$user, $server] = userAndServer();

    // Three /etc/passwd rows: one orphan, one protected (matches ssh_user "dply"),
    // one with an assigned site. Only the orphan should land in the bulk job.
    seedRemote($server, 'app-user');
    seedRemote($server, 'dply');
    seedRemote($server, 'owned-user');
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'php_fpm_user' => 'owned-user',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('queueRemoveOrphans')
        ->assertHasNoErrors();

    Bus::assertDispatched(
        DeleteOrphanSystemUsersJob::class,
        fn (DeleteOrphanSystemUsersJob $job): bool => $job->serverId === $server->id
            && $job->usernames === ['app-user']
            && $job->userId === $user->id,
    );
});
test('queue remove orphans toasts and skips when no orphans', function () {
    Bus::fake();
    [$user, $server] = userAndServer();

    // Only protected + in-use users — bulk button should be a no-op.
    seedRemote($server, 'dply');
    seedRemote($server, 'owned-user');
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'php_fpm_user' => 'owned-user',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('queueRemoveOrphans')
        ->assertHasNoErrors();

    Bus::assertNotDispatched(DeleteOrphanSystemUsersJob::class);
});
test('queue remove orphans marks users pending and seeds console row', function () {
    Bus::fake();
    [$user, $server] = userAndServer();
    seedRemote($server, 'app-user');
    seedRemote($server, 'queue-runner');

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('queueRemoveOrphans')
        ->assertHasNoErrors()
        ->assertSet('pending_remove_usernames', ['app-user', 'queue-runner']);

    expect(ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->where('kind', 'system_user')
        ->where('status', ConsoleAction::STATUS_QUEUED)
        ->count())->toBe(1);
});
test('open remove orphans confirm arms the shared modal', function () {
    [$user, $server] = userAndServer();
    seedRemote($server, 'app-user');

    Livewire::actingAs($user)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->call('openRemoveOrphansConfirm')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'queueRemoveOrphans')
        ->assertSet('confirmActionModalDestructive', true);
});
test('unauthorized user cannot render', function () {
    [$_, $server] = userAndServer();
    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)
        ->test(WorkspaceSystemUsers::class, ['server' => $server])
        ->assertForbidden();
});
