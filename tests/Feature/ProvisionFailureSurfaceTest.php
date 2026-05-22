<?php

declare(strict_types=1);

namespace Tests\Feature\ProvisionFailureSurfaceTest;
use App\Jobs\RunSetupScriptJob;
use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ServerProvisionFailedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('index card renders setup failed chip', function () {
    $user = userWithOrganization();
    $server = makeServer($user, [
        'name' => 'failed-host',
        'setup_status' => Server::SETUP_STATUS_FAILED,
    ]);

    $this->actingAs($user)
        ->get(route('servers.index'))
        ->assertOk()
        ->assertSee('failed-host')
        ->assertSee('Setup failed')
        // Banner counts the failed server and links to its journey.
        ->assertSee(route('servers.journey', $server), false)
        // The in-flight progress detail block (Step N of M / elapsed) must
        // not render when setup_status=failed.
        ->assertDontSee('Step 10 of 18');
});
test('index card provisioning chip stays for in flight setup', function () {
    $user = userWithOrganization();
    $server = makeServer($user, [
        'name' => 'in-flight-host',
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    $this->actingAs($user)
        ->get(route('servers.index'))
        ->assertOk()
        ->assertSee('in-flight-host')
        // Provisioning label stays; failed branch is not triggered.
        ->assertSee('provisioning')
        ->assertDontSee('Setup failed');
});
test('failure email dispatched on provision failure', function () {
    Notification::fake();

    $user = userWithOrganization();
    $server = makeServer($user, [
        'name' => 'doomed-host',
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
        'meta' => [
            'provision_step_snapshots' => [
                'step_one' => [
                    'label' => 'Installing PostgreSQL',
                    'output' => 'apt-get failed: package not found',
                ],
            ],
        ],
    ]);

    // Mark auto-retry as exhausted so applyProvisionOutcomeToServer skips
    // the retry branch and lands on the email/channel dispatch path.
    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'auto_retry_attempt' => RunSetupScriptJob::MAX_AUTO_RETRY_ATTEMPTS,
            'auto_retry_max' => RunSetupScriptJob::MAX_AUTO_RETRY_ATTEMPTS,
        ]),
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);

    Notification::assertSentTo(
        $user,
        ServerProvisionFailedNotification::class,
        function (ServerProvisionFailedNotification $n) use ($server) {
            return $n->server->is($server)
                && is_string($n->errorExcerpt)
                && str_contains($n->errorExcerpt, 'apt-get failed');
        }
    );

    expect($server->fresh()->setup_status)->toBe(Server::SETUP_STATUS_FAILED);
});
test('failure dispatched to org notification channels', function () {
    Http::fake();

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $channel = NotificationChannel::query()->create([
        'owner_type' => Organization::class,
        'owner_id' => $org->id,
        'label' => 'Ops Slack',
        'type' => NotificationChannel::TYPE_SLACK,
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
    ]);

    $server = makeServer($user, [
        'name' => 'doomed-host',
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
        'meta' => [
            'auto_retry_attempt' => RunSetupScriptJob::MAX_AUTO_RETRY_ATTEMPTS,
            'auto_retry_max' => RunSetupScriptJob::MAX_AUTO_RETRY_ATTEMPTS,
        ],
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);

    Http::assertSent(function ($request) use ($channel): bool {
        return $request->url() === $channel->config['webhook_url']
            && str_contains($request->body(), 'Server provisioning failed')
            && str_contains($request->body(), 'doomed-host');
    });
});
test('failure excerpt is null when no step snapshots', function () {
    Notification::fake();

    $user = userWithOrganization();
    $server = makeServer($user, [
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
        'meta' => [],
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);

    Notification::assertSentTo(
        $user,
        ServerProvisionFailedNotification::class,
        fn (ServerProvisionFailedNotification $n) => $n->errorExcerpt === null,
    );
});
test('failure publishes inbox event', function () {
    $user = userWithOrganization();
    $server = makeServer($user, [
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);

    $this->assertDatabaseHas('notification_events', [
        'event_key' => 'server.provision_failed',
        'subject_type' => Server::class,
        'subject_id' => $server->id,
        'severity' => 'warning',
    ]);
});
test('success dispatches to org channels with stack summary', function () {
    // Success path queues several follow-up jobs (insights, health, metrics,
    // inventory) that SSH out to the host; fake the queue so the test only
    // observes the local DB + outbound HTTP we care about.
    Bus::fake();
    Queue::fake();
    Http::fake();

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $channel = NotificationChannel::query()->create([
        'owner_type' => Organization::class,
        'owner_id' => $org->id,
        'label' => 'Ops Slack',
        'type' => NotificationChannel::TYPE_SLACK,
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
    ]);

    $server = makeServer($user, [
        'name' => 'fresh-host',
        'ip_address' => '203.0.113.50',
        'status' => Server::STATUS_PROVISIONING,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
        'meta' => [
            'php_version' => '8.4',
            'database' => 'postgres18',
            'cache_service' => 'redis',
            'webserver' => 'nginx',
        ],
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

    Http::assertSent(function ($request) use ($channel): bool {
        return $request->url() === $channel->config['webhook_url']
            && str_contains($request->body(), 'Server is ready')
            && str_contains($request->body(), 'fresh-host')
            && str_contains($request->body(), 'PHP 8.4')
            && str_contains($request->body(), 'PostgreSQL 18')
            && str_contains($request->body(), 'Redis')
            && str_contains($request->body(), 'Nginx');
    });
});
test('success publishes inbox event pointing at overview', function () {
    Bus::fake();
    Queue::fake();

    $user = userWithOrganization();
    $server = makeServer($user, [
        'name' => 'fresh-host',
        'status' => Server::STATUS_PROVISIONING,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

    $event = NotificationEvent::query()
        ->where('event_key', 'server.provisioned')
        ->where('subject_id', $server->id)
        ->first();

    expect($event)->not->toBeNull();
    expect($event->severity)->toBe('info');
    $this->assertStringContainsString(route('servers.overview', $server, absolute: false), (string) $event->url);
});
test('routing dedupe skips channels already dispatched', function () {
    Http::fake();

    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $channel = NotificationChannel::query()->create([
        'owner_type' => Organization::class,
        'owner_id' => $org->id,
        'label' => 'Ops Slack',
        'type' => NotificationChannel::TYPE_SLACK,
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
    ]);

    $server = makeServer($user, [
        'name' => 'doomed-host',
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    // Subscribe the same channel to this server's provision-failed event.
    // Without the dedupe, the publisher's routing pipe would send a second
    // copy on top of the direct fan-out from applyProvisionOutcomeToServer.
    NotificationSubscription::query()->create([
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Server::class,
        'subscribable_id' => $server->id,
        'event_key' => 'server.provision_failed',
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, false);

    // Channel should receive EXACTLY one webhook hit even though both the
    // direct path and the subscription pipe target it.
    Http::assertSentCount(1);
});
function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
/**
 * @param  array<string, mixed>  $overrides
 */
function makeServer(User $user, array $overrides = []): Server
{
    return Server::factory()->ready()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()?->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ], $overrides));
}
