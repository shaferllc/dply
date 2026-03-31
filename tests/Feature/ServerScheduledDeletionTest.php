<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Livewire\Servers\WorkspaceOverview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
