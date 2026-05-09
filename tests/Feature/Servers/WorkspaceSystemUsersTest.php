<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Jobs\CreateServerSystemUserJob;
use App\Jobs\DeleteServerSystemUserJob;
use App\Livewire\Servers\WorkspaceSystemUsers;
use App\Models\Organization;
use App\Models\Server;
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
        [$user, $server] = $this->userAndServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->set('new_username', 'app-user')
            ->set('new_sudo', true)
            ->call('queueCreate')
            ->assertHasNoErrors();

        Bus::assertDispatched(
            CreateServerSystemUserJob::class,
            fn (CreateServerSystemUserJob $job): bool => $job->serverId === $server->id
                && $job->username === 'app-user'
                && $job->grantSudo === true,
        );
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

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->set('remote_rows', [['username' => 'app-user', 'site_count' => 0]])
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

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->set('remote_rows', [['username' => 'app-user', 'site_count' => 0]])
            ->call('openRemoveModal', 'app-user')
            ->set('remove_confirm', 'wrong')
            ->call('queueRemove')
            ->assertHasErrors(['remove_confirm']);

        Bus::assertNotDispatched(DeleteServerSystemUserJob::class);
    }

    public function test_open_remove_modal_rejects_unknown_username(): void
    {
        [$user, $server] = $this->userAndServer();

        Livewire::actingAs($user)
            ->test(WorkspaceSystemUsers::class, ['server' => $server])
            ->set('remote_rows', [['username' => 'app-user', 'site_count' => 0]])
            ->call('openRemoveModal', 'something-not-listed')
            ->assertSet('remove_username', '');
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
