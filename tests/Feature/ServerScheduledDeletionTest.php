<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\WorkspaceOverview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ServerScheduledDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_scheduling_removal_sets_timestamp_and_keeps_server(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'sched-test-server',
        ]);

        $futureDate = now()->addDays(10)->toDateString();

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->call('openRemoveServerModal')
            ->set('removeMode', 'scheduled')
            ->set('scheduledRemovalDate', $futureDate)
            ->set('deleteConfirmName', 'sched-test-server')
            ->call('submitRemoveServer')
            ->assertHasNoErrors();

        $server->refresh();
        $this->assertNotNull($server->scheduled_deletion_at);
        $this->assertTrue($server->scheduled_deletion_at->isFuture());
    }

    public function test_wrong_confirm_name_is_rejected(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'exact-name',
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->call('openRemoveServerModal')
            ->set('deleteConfirmName', 'wrong')
            ->call('submitRemoveServer')
            ->assertHasErrors('deleteConfirmName');

        $this->assertModelExists($server->fresh());
    }

    public function test_process_scheduled_deletions_command_removes_due_servers(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $server->update(['scheduled_deletion_at' => now()->subMinute()]);

        $this->artisan(ProcessScheduledServerDeletionsCommand::class)->assertSuccessful();

        $this->assertModelMissing($server);
    }

    public function test_cancel_scheduled_removal_clears_timestamp(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $server->update(['scheduled_deletion_at' => now()->addWeek()]);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->call('cancelScheduledServerRemoval');

        $this->assertNull($server->fresh()->scheduled_deletion_at);
    }

    public function test_rerun_setup_queues_fresh_setup_attempt_for_existing_server(): void
    {
        Queue::fake();

        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ip_address' => '203.0.113.10',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_FAILED,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
                'provision_task_id' => 'old-task-id',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->call('rerunSetup')
            ->assertRedirect(route('servers.journey', $server));

        $server->refresh();

        $this->assertSame(Server::SETUP_STATUS_PENDING, $server->setup_status);
        $this->assertArrayNotHasKey('provision_task_id', $server->meta ?? []);

        Queue::assertPushed(WaitForServerSshReadyJob::class, function (WaitForServerSshReadyJob $job) use ($server) {
            return $job->server->is($server);
        });
    }
}
