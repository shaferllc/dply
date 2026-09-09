<?php

namespace Tests\Feature\UniversalNotificationsTest;

use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Notifications\Services\NotificationRoutingResolver;
use App\Modules\Notifications\Services\ServerDatabaseNotificationDispatcher;
use App\Notifications\CronJobAlertNotification;
use App\Notifications\OrganizationInvitationNotification;
use App\Notifications\ServerRemovalExecutedNotification;
use App\Notifications\ServerRemovalScheduledNotification;
use App\Notifications\SiteDeploymentCompletedNotification;
use App\Notifications\SshKeyRotationDueNotification;
use App\Notifications\SupervisorProgramsUnhealthyNotification;
use App\Notifications\UniversalEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::getFacadeRoot()->except([SendQueuedNotifications::class]);
});

test('publisher creates event and in app items for resource stakeholders', function () {
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
});

test('server database dispatcher publishes universal event and sends subscribed channels', function () {
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
});

test('authenticated user can view notifications inbox', function () {
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
});

test('publisher creates laravel database notifications for in app recipients', function () {
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
        'type' => UniversalEventNotification::class,
    ]);
});

test('user broadcast channel authorizes ulid user ids', function () {
    $user = User::factory()->create();

    $result = Broadcast::auth(['channel_name' => 'private-App.Models.User.'.$user->id], $user);

    $this->assertNotFalse($result);
});

test('deploy email notification renders from universal event metadata', function () {
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

    expect($mail->subject)->toBe('[Dply] api deploy FAILED');
    expect($mail->actionText)->toBe('Open site in Dply');
    expect($mail->actionUrl)->toBe('https://example.test/sites/api');
    expect($mail->introLines)->toContain('Site: **api**');
    expect($mail->introLines)->toContain('Trigger: git');
    expect($mail->introLines)->toContain('Status: **failed**');
    expect($mail->introLines)->toContain('Git SHA: `abc123`');
});

test('invitation email notification renders from universal event metadata', function () {
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
    expect($mail->actionText)->toBe('Accept invitation');
    $this->assertStringContainsString('invite-token', $mail->actionUrl);
    expect($mail->introLines)->toContain('You will be added as a admin');
});

test('operational mail wrappers render from event metadata', function () {
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
    expect($cronMail->actionText)->toBe('Open cron jobs');
    expect($cronMail->introLines)->toContain('Exit code: 1');

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
    expect($sshMail->actionText)->toBe('Open SSH keys');

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
    expect($supervisorMail->actionText)->toBe('Open Daemons');
    expect($supervisorMail->introLines)->toContain('Organization: Acme');
});

test('server removal mail wrappers render from event metadata', function () {
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
    expect($scheduledMail->actionText)->toBe('Open server');
    expect($scheduledMail->introLines)->toContain('Scheduled by: Taylor');

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
    expect($executedMail->introLines)->toContain('The server was deleted after the scheduled window elapsed.');
});

test('an org-wide subscription routes events for every resource in the org', function () {
    // This is the capability that replaced NotificationWebhookDestination's
    // "All sites in this org" scope. Before it, a subscription could only name
    // one server or site, which is the whole reason the parallel webhook system
    // existed. The subscription below names the ORGANIZATION and must still fire
    // for an event whose resource is a server.
    Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'name' => 'web-1',
    ]);

    $channel = NotificationChannel::factory()->forUser($owner)->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Ops',
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
    ]);

    NotificationSubscription::query()->create([
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Organization::class,
        'subscribable_id' => $org->id,
        'event_key' => 'server.insights_alerts',
    ]);

    $event = NotificationEvent::query()->create([
        'event_key' => 'server.insights_alerts',
        'resource_type' => Server::class,
        'resource_id' => $server->id,
        'organization_id' => $org->id,
        'title' => 'Disk almost full',
        'body' => 'web-1 is at 92%.',
        'severity' => 'warning',
        'category' => 'server',
        'supports_in_app' => false,
        'supports_email' => false,
        'supports_webhook' => true,
        'occurred_at' => now(),
    ]);

    app(NotificationRoutingResolver::class)->route($event);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
});

test('a channel subscribed both per-resource and org-wide is only messaged once', function () {
    // The two subscription shapes are OR-ed in one query, so without the
    // unique() on channel id an operator who ticked both would get doubles.
    Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $server = Server::factory()->create(['user_id' => $owner->id, 'organization_id' => $org->id]);

    $channel = NotificationChannel::factory()->forUser($owner)->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Ops',
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
    ]);

    foreach ([[Organization::class, $org->id], [Server::class, $server->id]] as [$type, $id]) {
        NotificationSubscription::query()->create([
            'notification_channel_id' => $channel->id,
            'subscribable_type' => $type,
            'subscribable_id' => $id,
            'event_key' => 'server.insights_alerts',
        ]);
    }

    $event = NotificationEvent::query()->create([
        'event_key' => 'server.insights_alerts',
        'resource_type' => Server::class,
        'resource_id' => $server->id,
        'organization_id' => $org->id,
        'title' => 'Disk almost full',
        'severity' => 'warning',
        'category' => 'server',
        'supports_in_app' => false,
        'supports_email' => false,
        'supports_webhook' => true,
        'occurred_at' => now(),
    ]);

    app(NotificationRoutingResolver::class)->route($event);

    $sent = 0;
    Http::assertSent(function ($request) use (&$sent) {
        if (str_contains($request->url(), 'hooks.slack.com')) {
            $sent++;
        }

        return true;
    });

    expect($sent)->toBe(1);
});
