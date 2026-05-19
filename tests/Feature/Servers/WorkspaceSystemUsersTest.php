<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceSystemUsersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Server}
     */
    private function userAndServer(): array
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

    public function test_renders_for_a_server_the_user_can_view(): void
    {
        [$user, $server] = $this->userAndServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->assertOk()
            ->assertSee('System users');
    }

    public function test_create_dispatches_create_server_system_user_job(): void
    {
        Bus::fake();
        config(['site_settings.vm_site_file_web_group' => 'www-data']);
        [$user, $server] = $this->userAndServer();

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
    }

    public function test_create_omits_web_group_when_unchecked(): void
    {
        Bus::fake();
        config(['site_settings.vm_site_file_web_group' => 'www-data']);
        [$user, $server] = $this->userAndServer();

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
    }

    public function test_create_rejects_unsupported_shell(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->set('new_username', 'app-user')
            ->set('new_shell', '/bin/zsh')
            ->call('queueCreate')
            ->assertHasErrors(['new_shell']);

        Bus::assertNotDispatched(CreateServerSystemUserJob::class);
    }

    public function test_create_validates_username_format(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->set('new_username', 'BadName!')
            ->call('queueCreate')
            ->assertHasErrors(['new_username']);

        Bus::assertNotDispatched(CreateServerSystemUserJob::class);
    }

    public function test_remove_dispatches_delete_server_system_user_job(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();
        $this->seedRemote($server, 'app-user');

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
    }

    public function test_remove_requires_confirm_to_match_username(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();
        $this->seedRemote($server, 'app-user');

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->call('openRemoveModal', 'app-user')
            ->set('remove_confirm', 'wrong')
            ->call('queueRemove')
            ->assertHasErrors(['remove_confirm']);

        Bus::assertNotDispatched(DeleteServerSystemUserJob::class);
    }

    public function test_open_remove_modal_rejects_unknown_username(): void
    {
        [$user, $server] = $this->userAndServer();
        $this->seedRemote($server, 'app-user');

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->call('openRemoveModal', 'something-not-listed')
            ->assertSet('remove_username', '');
    }

    private function seedRemote(Server $server, string $username): void
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

    public function test_load_users_dispatches_sync_job_and_seeds_console_row(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->call('loadUsers')
            ->assertHasNoErrors();

        Bus::assertDispatched(
            SyncServerSystemUsersJob::class,
            fn (SyncServerSystemUsersJob $job): bool => $job->serverId === $server->id
                && $job->userId === $user->id,
        );

        $this->assertSame(1, ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'system_user')
            ->where('status', ConsoleAction::STATUS_QUEUED)
            ->count());
    }

    public function test_create_seeds_a_queued_console_row(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();

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

        $this->assertNotNull($row);
        $this->assertStringContainsString('app-user', (string) $row->label);
    }

    public function test_queue_remove_orphans_dispatches_bulk_job_with_orphans_only(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();

        // Three /etc/passwd rows: one orphan, one protected (matches ssh_user "dply"),
        // one with an assigned site. Only the orphan should land in the bulk job.
        $this->seedRemote($server, 'app-user');
        $this->seedRemote($server, 'dply');
        $this->seedRemote($server, 'owned-user');
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
    }

    public function test_queue_remove_orphans_toasts_and_skips_when_no_orphans(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();

        // Only protected + in-use users — bulk button should be a no-op.
        $this->seedRemote($server, 'dply');
        $this->seedRemote($server, 'owned-user');
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
    }

    public function test_queue_remove_orphans_marks_users_pending_and_seeds_console_row(): void
    {
        Bus::fake();
        [$user, $server] = $this->userAndServer();
        $this->seedRemote($server, 'app-user');
        $this->seedRemote($server, 'queue-runner');

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->call('queueRemoveOrphans')
            ->assertHasNoErrors()
            ->assertSet('pending_remove_usernames', ['app-user', 'queue-runner']);

        $this->assertSame(1, ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'system_user')
            ->where('status', ConsoleAction::STATUS_QUEUED)
            ->count());
    }

    public function test_open_remove_orphans_confirm_arms_the_shared_modal(): void
    {
        [$user, $server] = $this->userAndServer();
        $this->seedRemote($server, 'app-user');

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->call('openRemoveOrphansConfirm')
            ->assertSet('showConfirmActionModal', true)
            ->assertSet('confirmActionModalMethod', 'queueRemoveOrphans')
            ->assertSet('confirmActionModalDestructive', true);
    }

    public function test_unauthorized_user_cannot_render(): void
    {
        [$_, $server] = $this->userAndServer();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->assertForbidden();
    }
}
