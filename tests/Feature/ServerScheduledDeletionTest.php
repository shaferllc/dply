<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ProcessScheduledServerDeletionsCommand;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\WorkspaceOverview;
use App\Models\NotificationChannel;
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
        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'server.removal.scheduled',
            'subject_type' => Server::class,
            'subject_id' => $server->id,
            'organization_id' => $org->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => \App\Notifications\UniversalEventNotification::class,
        ]);
    }

    public function test_scheduling_removal_no_longer_requires_name_confirmation(): void
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
            ->set('removeMode', 'scheduled')
            ->set('scheduledRemovalDate', now()->addDays(3)->toDateString())
            ->call('submitRemoveServer')
            ->assertHasNoErrors();

        $server->refresh();

        $this->assertNotNull($server->scheduled_deletion_at);
        $this->assertTrue($server->scheduled_deletion_at->isFuture());
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
        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'server.removal.executed',
            'organization_id' => $org->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => \App\Notifications\UniversalEventNotification::class,
        ]);
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

    public function test_server_overview_exposes_notification_management_actions(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'notify-server',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        $this->actingAs($user)
            ->get(route('servers.overview', $server))
            ->assertOk()
            ->assertSee('Server notifications')
            ->assertSee('My channels')
            ->assertSee('Organization channels')
            ->assertSee('Assign server events');
    }

    public function test_server_overview_can_quick_assign_server_notifications(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'notify-server',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        $channel = NotificationChannel::factory()->forUser($user)->create([
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Ops',
            'config' => [
                'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
            ],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->set('quick_notification_channel_ids', [(string) $channel->id])
            ->set('quick_notification_event_keys', ['server.monitoring', 'server.ssh_login'])
            ->call('saveQuickNotificationAssignments')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => 'server.monitoring',
        ]);

        $this->assertDatabaseHas('notification_subscriptions', [
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => 'server.ssh_login',
        ]);
    }

    public function test_server_overview_can_quick_add_notification_channel(): void
    {
        $user = $this->userWithOrganization();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'notify-server',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
            'setup_status' => Server::SETUP_STATUS_DONE,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->set('quick_new_owner_scope', 'personal')
            ->set('quick_new_type', NotificationChannel::TYPE_SLACK)
            ->set('quick_new_label', 'Ops alerts')
            ->set('quick_new_slack_webhook_url', 'https://hooks.slack.com/services/T/B/X')
            ->call('createQuickNotificationChannel')
            ->assertHasNoErrors()
            ->assertSet('quick_new_label', '');

        $this->assertDatabaseHas('notification_channels', [
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Ops alerts',
        ]);
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
