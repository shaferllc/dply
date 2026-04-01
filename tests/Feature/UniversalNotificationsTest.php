<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationSubscription;
use App\Models\NotificationWebhookDestination;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Notifications\CronJobAlertNotification;
use App\Notifications\OrganizationInvitationNotification;
use App\Notifications\ServerRemovalExecutedNotification;
use App\Notifications\ServerRemovalScheduledNotification;
use App\Notifications\SiteDeploymentCompletedNotification;
use App\Notifications\SshKeyRotationDueNotification;
use App\Notifications\SupervisorProgramsUnhealthyNotification;
use App\Models\User;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Notifications\ServerDatabaseNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UniversalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_publisher_creates_event_and_in_app_items_for_resource_stakeholders(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($admin->id, ['role' => 'admin']);

        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'name' => 'web-1',
        ]);

        app(NotificationPublisher::class)->publish(
            eventKey: 'server.monitoring',
            subject: $server,
            title: 'Server unavailable',
            body: 'web-1 stopped responding to health checks.',
            url: route('servers.monitor', $server, absolute: true),
        );

        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'server.monitoring',
            'subject_type' => Server::class,
            'subject_id' => $server->id,
            'organization_id' => $org->id,
            'title' => 'Server unavailable',
        ]);

        $this->assertDatabaseHas('notification_inbox_items', [
            'user_id' => $owner->id,
            'title' => 'Server unavailable',
        ]);

        $this->assertDatabaseHas('notification_inbox_items', [
            'user_id' => $admin->id,
            'title' => 'Server unavailable',
        ]);
    }

    public function test_server_database_dispatcher_publishes_universal_event_and_sends_subscribed_channels(): void
    {
        Http::fake([
            'hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        $owner = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
            'name' => 'db-1',
        ]);

        $database = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'engine' => 'mysql',
            'name' => 'app_db',
            'username' => 'app_user',
            'password' => 'secret',
            'host' => '127.0.0.1',
        ]);

        $channel = NotificationChannel::factory()->forUser($owner)->create([
            'type' => NotificationChannel::TYPE_SLACK,
            'label' => 'Ops',
            'config' => [
                'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
            ],
        ]);

        NotificationSubscription::query()->create([
            'notification_channel_id' => $channel->id,
            'subscribable_type' => Server::class,
            'subscribable_id' => $server->id,
            'event_key' => 'server.database.created',
        ]);

        app(ServerDatabaseNotificationDispatcher::class)->notifyIfSubscribed($server, 'created', $database, $owner);

        $this->assertDatabaseHas('notification_events', [
            'event_key' => 'server.database.created',
            'subject_type' => ServerDatabase::class,
            'subject_id' => $database->id,
            'organization_id' => $org->id,
        ]);

        $this->assertDatabaseHas('notification_inbox_items', [
            'user_id' => $owner->id,
            'resource_type' => Server::class,
            'resource_id' => $server->id,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
    }

    public function test_authenticated_user_can_view_notifications_inbox(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $site = Site::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        app(NotificationPublisher::class)->publish(
            eventKey: 'site.deployments',
            subject: $site,
            title: 'Deploy finished',
            body: 'Production deploy completed successfully.',
            url: route('sites.show', [$site->server, $site], absolute: true),
        );

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notifications')
            ->assertSee('Deploy finished')
            ->assertSee('Production deploy completed successfully.');
    }

    public function test_publisher_creates_laravel_database_notifications_for_in_app_recipients(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'api-1',
        ]);

        app(NotificationPublisher::class)->publish(
            eventKey: 'server.monitoring',
            subject: $server,
            title: 'Server degraded',
            body: 'api-1 is reporting elevated latency.',
            url: route('servers.monitor', $server, absolute: true),
            recipientUsers: [$user],
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => \App\Notifications\UniversalEventNotification::class,
        ]);
    }

    public function test_user_broadcast_channel_authorizes_ulid_user_ids(): void
    {
        $user = User::factory()->create();

        $result = \Illuminate\Support\Facades\Broadcast::auth(['channel_name' => 'private-App.Models.User.'.$user->id], $user);

        $this->assertNotFalse($result);
    }

    public function test_publisher_routes_deploy_events_to_org_webhook_destinations(): void
    {
        Http::fake([
            '*' => Http::response('ok', 200),
        ]);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $site = Site::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        NotificationWebhookDestination::query()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'name' => 'Deploy hook',
            'driver' => NotificationWebhookDestination::DRIVER_SLACK,
            'webhook_url' => 'https://example.test/deploy-hook',
            'events' => ['deploy_failed'],
            'enabled' => true,
        ]);

        app(NotificationPublisher::class)->publish(
            eventKey: 'site.deployments',
            subject: $site,
            title: 'Deploy failed',
            body: 'Production deploy failed.',
            url: route('sites.show', [$site->server, $site], absolute: true),
            metadata: [
                'site_id' => $site->id,
                'status' => 'failed',
            ],
            recipientUsers: [$user],
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://example.test/deploy-hook');
    }

    public function test_deploy_email_notification_renders_from_universal_event_metadata(): void
    {
        $user = User::factory()->create();

        $event = NotificationEvent::query()->create([
            'event_key' => 'site.deployments',
            'title' => '[Dply] api deploy FAILED',
            'body' => 'Trigger: git',
            'url' => 'https://example.test/sites/api',
            'severity' => 'error',
            'category' => 'deployments',
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_webhook' => true,
            'metadata' => [
                'site_name' => 'api',
                'status' => 'failed',
                'trigger' => 'git',
                'git_sha' => 'abc123',
                'log_excerpt' => 'Deploy log excerpt',
            ],
            'occurred_at' => now(),
        ]);

        $mail = (new SiteDeploymentCompletedNotification($event))->toMail($user);

        $this->assertSame('[Dply] api deploy FAILED', $mail->subject);
        $this->assertSame('Open site in Dply', $mail->actionText);
        $this->assertSame('https://example.test/sites/api', $mail->actionUrl);
        $this->assertContains('Site: **api**', $mail->introLines);
        $this->assertContains('Trigger: git', $mail->introLines);
        $this->assertContains('Status: **failed**', $mail->introLines);
        $this->assertContains('Git SHA: `abc123`', $mail->introLines);
    }

    public function test_invitation_email_notification_renders_from_universal_event_metadata(): void
    {
        $user = User::factory()->create();

        $event = NotificationEvent::query()->create([
            'event_key' => 'organization.invitation.sent',
            'title' => 'Invitation sent',
            'body' => 'invitee@example.com was invited.',
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_webhook' => false,
            'metadata' => [
                'organization_name' => 'Acme',
                'inviter_name' => 'Taylor',
                'role' => 'admin',
                'invitation_token' => 'invite-token',
            ],
            'occurred_at' => now(),
        ]);

        $mail = (new OrganizationInvitationNotification($event))->toMail($user);

        $this->assertStringContainsString('Invitation', $mail->subject);
        $this->assertSame('Accept invitation', $mail->actionText);
        $this->assertStringContainsString('invite-token', $mail->actionUrl);
        $this->assertContains('You will be added as a admin', $mail->introLines);
    }

    public function test_operational_mail_wrappers_render_from_event_metadata(): void
    {
        $user = User::factory()->create();

        $cronEvent = NotificationEvent::query()->create([
            'event_key' => 'server.cron.alert',
            'title' => '[Dply] Cron job alert on web-1',
            'url' => 'https://example.test/servers/web-1/cron',
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_webhook' => true,
            'metadata' => [
                'server_name' => 'web-1',
                'cron_job_description' => 'Nightly backup',
                'failure' => true,
                'exit_code' => 1,
                'output_excerpt' => 'Backup failed',
            ],
            'occurred_at' => now(),
        ]);
        $cronMail = (new CronJobAlertNotification($cronEvent))->toMail($user);
        $this->assertSame('Open cron jobs', $cronMail->actionText);
        $this->assertContains('Exit code: 1', $cronMail->introLines);

        $sshEvent = NotificationEvent::query()->create([
            'event_key' => 'server.ssh_key_rotation_due',
            'title' => 'SSH key review due',
            'url' => 'https://example.test/servers/web-1/ssh-keys',
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_webhook' => false,
            'metadata' => [
                'authorized_key_name' => 'deploy key',
                'server_name' => 'web-1',
            ],
            'occurred_at' => now(),
        ]);
        $sshMail = (new SshKeyRotationDueNotification($sshEvent))->toMail($user);
        $this->assertSame('Open SSH keys', $sshMail->actionText);

        $supervisorEvent = NotificationEvent::query()->create([
            'event_key' => 'server.supervisor.unhealthy',
            'title' => '[Dply] Supervisor programs need attention',
            'url' => 'https://example.test/servers/web-1/daemons',
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_webhook' => true,
            'metadata' => [
                'server_name' => 'web-1',
                'organization_name' => 'Acme',
                'summary' => 'worker is STOPPED',
            ],
            'occurred_at' => now(),
        ]);
        $supervisorMail = (new SupervisorProgramsUnhealthyNotification($supervisorEvent))->toMail($user);
        $this->assertSame('Open Daemons', $supervisorMail->actionText);
        $this->assertContains('Organization: Acme', $supervisorMail->introLines);
    }

    public function test_server_removal_mail_wrappers_render_from_event_metadata(): void
    {
        $user = User::factory()->create();

        $scheduledEvent = NotificationEvent::query()->create([
            'event_key' => 'server.removal.scheduled',
            'title' => '[Dply] web-1 removal scheduled',
            'url' => 'https://example.test/servers/web-1',
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_webhook' => false,
            'metadata' => [
                'server_name' => 'web-1',
                'organization_name' => 'Acme',
                'scheduled_for_display' => 'Apr 10, 2026',
                'reason' => 'Retiring hardware',
                'actor_name' => 'Taylor',
            ],
            'occurred_at' => now(),
        ]);
        $scheduledMail = (new ServerRemovalScheduledNotification($scheduledEvent))->toMail($user);
        $this->assertSame('Open server', $scheduledMail->actionText);
        $this->assertContains('Scheduled by: Taylor', $scheduledMail->introLines);

        $executedEvent = NotificationEvent::query()->create([
            'event_key' => 'server.removal.executed',
            'title' => '[Dply] web-1 removed',
            'body' => 'The server was deleted after the scheduled window elapsed.',
            'supports_in_app' => true,
            'supports_email' => true,
            'supports_webhook' => false,
            'metadata' => [
                'server_name' => 'web-1',
                'organization_name' => 'Acme',
            ],
            'occurred_at' => now(),
        ]);
        $executedMail = (new ServerRemovalExecutedNotification($executedEvent))->toMail($user);
        $this->assertContains('The server was deleted after the scheduled window elapsed.', $executedMail->introLines);
    }
}
