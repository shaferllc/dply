<?php

namespace Tests\Feature\PagerDutyNotificationChannelTest;

use App\Livewire\Settings\NotificationChannels as ProfileNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\Server;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationRoutingResolver;
use App\Notifications\Concerns\DeliversToPagerDuty;
use App\Notifications\ServerProvisionFailedNotification;
use App\Notifications\WebserverHealthAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function pagerDutyConfig(array $overrides = []): array
{
    return array_merge([
        'routing_key' => '99dc10c97a6e43c387bbc4f877c794ef',
        'region' => 'us',
        'default_severity' => 'error',
        'source' => null,
        'component' => null,
        'group' => null,
    ], $overrides);
}

function pagerDutyChannelFor(User $user, array $overrides = []): NotificationChannel
{
    return $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_PAGERDUTY,
        'label' => 'On-call',
        'config' => pagerDutyConfig($overrides),
    ]);
}

test('pagerduty is an offered channel type by default', function () {
    expect(NotificationChannel::typesForUi())->toContain(NotificationChannel::TYPE_PAGERDUTY);
    expect(NotificationChannel::labelForType(NotificationChannel::TYPE_PAGERDUTY))->toBe('PagerDuty');
});

test('user can create a pagerduty channel and the key is encrypted at rest', function () {
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_PAGERDUTY)
        ->set('new_label', 'On-call')
        ->set('new_pagerduty_routing_key', 'super-secret-routing-key')
        ->set('new_pagerduty_region', 'eu')
        ->set('new_pagerduty_default_severity', 'critical')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = $user->notificationChannels()->firstOrFail();

    expect($channel->config['routing_key'])->toBe('super-secret-routing-key');
    expect($channel->config['region'])->toBe('eu');

    expect((string) DB::table('notification_channels')->value('config'))
        ->not->toContain('super-secret-routing-key');
});

test('an invalid severity is rejected', function () {
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_PAGERDUTY)
        ->set('new_label', 'On-call')
        ->set('new_pagerduty_routing_key', 'key')
        ->set('new_pagerduty_default_severity', 'catastrophic')
        ->call('createChannel')
        ->assertHasErrors('new_pagerduty_default_severity');
});

test('send test raises an info incident so it cannot wake on-call', function () {
    Http::fake(['events.pagerduty.com/*' => Http::response(['status' => 'success', 'dedup_key' => 'abc'], 202)]);

    $user = User::factory()->create();
    // Channel default is `error`; the test must still go out as `info`.
    $channel = pagerDutyChannelFor($user, ['default_severity' => 'critical']);

    expect($channel->sendTest($user)['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://events.pagerduty.com/v2/enqueue'
            && $request['event_action'] === 'trigger'
            && $request['payload']['severity'] === 'info';
    });
});

test('send test routes to the EU host', function () {
    Http::fake(['events.eu.pagerduty.com/*' => Http::response(['status' => 'success'], 202)]);

    $user = User::factory()->create();
    $channel = pagerDutyChannelFor($user, ['region' => 'eu']);

    expect($channel->sendTest($user)['ok'])->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://events.eu.pagerduty.com/v2/enqueue');
});

test('a bad key 400 is reported as a key problem not a payload problem', function () {
    Http::fake(['events.pagerduty.com/*' => Http::response([
        'status' => 'invalid event',
        'errors' => ["Event object is invalid: 'routing_key' is invalid"],
    ], 400)]);

    $user = User::factory()->create();
    $channel = pagerDutyChannelFor($user);

    $result = $channel->sendTest($user);

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('integration key');
});

test('send test refuses before hitting the network when the key is missing', function () {
    Http::fake();

    $user = User::factory()->create();
    $channel = pagerDutyChannelFor($user, ['routing_key' => '']);

    expect($channel->sendTest($user)['ok'])->toBeFalse();

    Http::assertNothingSent();
});

test('routed events carry their own severity and a stable dedup key', function () {
    Http::fake(['events.pagerduty.com/*' => Http::response(['status' => 'success'], 202)]);

    $user = User::factory()->create();
    $channel = pagerDutyChannelFor($user);

    $event = NotificationEvent::query()->create([
        'event_key' => 'server.insights_alerts',
        'resource_type' => Server::class,
        'resource_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        'title' => 'Disk almost full',
        'body' => 'web-1 is at 92%.',
        'severity' => 'critical',
        'category' => 'server',
        'supports_in_app' => false,
        'supports_email' => false,
        'supports_webhook' => true,
        'occurred_at' => now(),
    ]);

    $channel->subscriptions()->create([
        'subscribable_type' => Server::class,
        'subscribable_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        'event_key' => 'server.insights_alerts',
    ]);

    app(NotificationRoutingResolver::class)->route($event);

    Http::assertSent(function ($request) {
        return $request['payload']['severity'] === 'critical'
            // Keyed on resource + event, NOT the event id — the whole point is
            // that the next identical alert updates this incident.
            && $request['dedup_key'] === 'dply:'.Server::class.':01HZZZZZZZZZZZZZZZZZZZZZZZ:server.insights_alerts'
            && str_contains((string) $request['payload']['summary'], 'Disk almost full');
    });
});

test('two alerts for the same condition share one dedup key', function () {
    Http::fake(['events.pagerduty.com/*' => Http::response(['status' => 'success'], 202)]);

    $user = User::factory()->create();
    $channel = pagerDutyChannelFor($user);

    $context = ['severity' => 'error', 'dedup_key' => 'dply:server:1:disk', 'event_key' => 'server.disk'];
    $channel->sendOperationalMessage('Disk almost full', 'web-1 at 92%', null, null, $context);
    $channel->sendOperationalMessage('Disk almost full', 'web-1 at 95%', null, null, $context);

    $keys = [];
    Http::assertSent(function ($request) use (&$keys) {
        $keys[] = $request['dedup_key'];

        return true;
    });

    expect($keys)->toBe(['dply:server:1:disk', 'dply:server:1:disk']);
});

test('resolvePagerDutyAlert closes the incident by dedup key', function () {
    Http::fake(['events.pagerduty.com/*' => Http::response(['status' => 'success'], 202)]);

    $user = User::factory()->create();
    $channel = pagerDutyChannelFor($user);

    $channel->resolvePagerDutyAlert('dply:server:1:disk');

    Http::assertSent(function ($request) {
        return $request['event_action'] === 'resolve'
            && $request['dedup_key'] === 'dply:server:1:disk';
    });
});

test('chat-shaped channels ignore the alert context entirely', function () {
    Http::fake();

    $user = User::factory()->create();
    $slack = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Ops',
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'],
    ]);

    $slack->sendOperationalMessage('Disk almost full', 'web-1 at 92%', null, null, [
        'severity' => 'critical',
        'dedup_key' => 'dply:server:1:disk',
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
});

test('the trait default is silence', function () {
    $user = User::factory()->create();
    pagerDutyChannelFor($user);

    // A class that uses the trait but declares no severity must not page, even
    // with a PagerDuty channel configured. This is the safety default.
    $silent = new class extends Notification
    {
        use DeliversToPagerDuty;
    };

    expect($silent->pagerDutySeverity($user))->toBeNull();
    expect($silent->viaPagerDuty($user->fresh()))->toBe([]);
});

test('a failure notification pages at the declared severity', function () {
    $user = User::factory()->create();
    pagerDutyChannelFor($user);

    $server = Server::factory()->create();
    $notification = new ServerProvisionFailedNotification($server, 'boom');

    expect($notification->pagerDutySeverity($user))->toBe('critical');
    expect($notification->viaPagerDuty($user->fresh()))->toBe(['PagerDuty']);
    expect($notification->pagerDutyDedupKey($user))->toBe('dply:provision-failed:'.$server->id);
});

test('a recovered health alert resolves rather than triggers', function () {
    $user = User::factory()->create();
    pagerDutyChannelFor($user);
    $server = Server::factory()->create();

    $tripped = new WebserverHealthAlertNotification($server, 'nginx', 'cpu', 'tripped', 'critical', 95.0, 90.0, '>');
    $recovered = new WebserverHealthAlertNotification($server, 'nginx', 'cpu', 'recovered', 'critical', 12.0, 90.0, '>');

    $notifiable = $user->fresh();

    expect($tripped->toPagerDuty($notifiable)->toArray()['event_action'])->toBe('trigger');
    expect($recovered->toPagerDuty($notifiable)->toArray()['event_action'])->toBe('resolve');

    // Both must name the SAME incident, or the recovery closes nothing.
    expect($recovered->pagerDutyDedupKey($notifiable))
        ->toBe($tripped->pagerDutyDedupKey($notifiable));
});
