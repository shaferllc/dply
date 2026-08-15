<?php

namespace Tests\Feature\IntercomNotificationChannelTest;

use App\Livewire\Organizations\NotificationChannels as OrgNotificationChannels;
use App\Livewire\Settings\NotificationChannels as ProfileNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\SshKeyRotationDueNotification;
use App\Notifications\UniversalEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function intercomConfig(array $overrides = []): array
{
    return array_merge([
        'access_token' => 'dG9rZW4tc2VjcmV0',
        'region' => 'us',
        'admin_id' => '394051',
        'recipient' => 'ops@acme.test',
        'recipient_type' => NotificationChannel::INTERCOM_TO_USER_EMAIL,
        'message_type' => 'inapp',
        'template' => 'plain',
        'subject' => null,
    ], $overrides);
}

test('intercom is an offered channel type by default', function () {
    expect(NotificationChannel::typesForUi())->toContain(NotificationChannel::TYPE_INTERCOM);
    expect(NotificationChannel::labelForType(NotificationChannel::TYPE_INTERCOM))->toBe('Intercom');
});

test('user can create a personal intercom channel', function () {
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_INTERCOM)
        ->set('new_label', 'Ops inbox')
        ->set('new_intercom_access_token', 'dG9rZW4tc2VjcmV0')
        ->set('new_intercom_region', 'eu')
        ->set('new_intercom_admin_id', '394051')
        ->set('new_intercom_recipient_type', NotificationChannel::INTERCOM_TO_USER_EMAIL)
        ->set('new_intercom_recipient', 'ops@acme.test')
        ->set('new_intercom_message_type', 'inapp')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = $user->notificationChannels()->firstOrFail();

    expect($channel->type)->toBe(NotificationChannel::TYPE_INTERCOM);
    expect($channel->config['access_token'])->toBe('dG9rZW4tc2VjcmV0');
    expect($channel->config['region'])->toBe('eu');
    expect($channel->config['admin_id'])->toBe('394051');
});

test('the access token is encrypted at rest', function () {
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_INTERCOM)
        ->set('new_label', 'Ops inbox')
        ->set('new_intercom_access_token', 'super-secret-intercom-token')
        ->set('new_intercom_admin_id', '394051')
        ->set('new_intercom_recipient', 'ops@acme.test')
        ->call('createChannel')
        ->assertHasNoErrors();

    $raw = (string) DB::table('notification_channels')->value('config');

    expect($raw)->not->toContain('super-secret-intercom-token');
});

test('an email message requires a subject', function () {
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_INTERCOM)
        ->set('new_label', 'Ops inbox')
        ->set('new_intercom_access_token', 'dG9rZW4tc2VjcmV0')
        ->set('new_intercom_admin_id', '394051')
        ->set('new_intercom_recipient', 'ops@acme.test')
        ->set('new_intercom_message_type', 'email')
        ->set('new_intercom_subject', '')
        ->call('createChannel')
        ->assertHasErrors('new_intercom_subject');
});

test('a user-email recipient must be an email address', function () {
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_INTERCOM)
        ->set('new_label', 'Ops inbox')
        ->set('new_intercom_access_token', 'dG9rZW4tc2VjcmV0')
        ->set('new_intercom_admin_id', '394051')
        ->set('new_intercom_recipient_type', NotificationChannel::INTERCOM_TO_USER_EMAIL)
        ->set('new_intercom_recipient', 'not-an-email')
        ->call('createChannel')
        ->assertHasErrors('new_intercom_recipient');
});

test('a user-id recipient is not held to the email rule', function () {
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_INTERCOM)
        ->set('new_label', 'Ops inbox')
        ->set('new_intercom_access_token', 'dG9rZW4tc2VjcmV0')
        ->set('new_intercom_admin_id', '394051')
        ->set('new_intercom_recipient_type', NotificationChannel::INTERCOM_TO_USER_ID)
        ->set('new_intercom_recipient', '536e564f316c83104c000020')
        ->call('createChannel')
        ->assertHasNoErrors();
});

test('org admin can create an org intercom channel', function () {
    Http::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->test(OrgNotificationChannels::class, ['organization' => $org])
        ->set('new_type', NotificationChannel::TYPE_INTERCOM)
        ->set('new_label', 'Acme Intercom')
        ->set('new_intercom_access_token', 'dG9rZW4tc2VjcmV0')
        ->set('new_intercom_admin_id', '394051')
        ->set('new_intercom_recipient', 'ops@acme.test')
        ->call('createChannel')
        ->assertHasNoErrors();

    expect($org->notificationChannels()->where('type', NotificationChannel::TYPE_INTERCOM)->count())->toBe(1);
});

test('send test posts to the intercom messages endpoint with a bearer token', function () {
    Http::fake(['api.intercom.io/*' => Http::response(['type' => 'user_message', 'id' => '1'], 200)]);

    $user = User::factory()->create();
    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(),
    ]);

    $result = $channel->sendTest($user);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.intercom.io/messages'
            && $request->hasHeader('Authorization', 'Bearer dG9rZW4tc2VjcmV0')
            && $request->hasHeader('Intercom-Version')
            && $request['message_type'] === 'inapp'
            && $request['from'] === ['type' => 'admin', 'id' => '394051']
            && $request['to'] === ['type' => 'user', 'email' => 'ops@acme.test'];
    });
});

test('send test routes to the regional host', function () {
    Http::fake(['api.eu.intercom.io/*' => Http::response(['id' => '1'], 200)]);

    $user = User::factory()->create();
    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'EU inbox',
        'config' => intercomConfig(['region' => 'eu']),
    ]);

    expect($channel->sendTest($user)['ok'])->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.eu.intercom.io/messages');
});

test('send test surfaces fixable copy for a rejected token', function () {
    Http::fake(['api.intercom.io/*' => Http::response([
        'type' => 'error.list',
        'errors' => [['code' => 'token_unauthorized', 'message' => 'Unauthorized']],
    ], 401)]);

    $user = User::factory()->create();
    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(),
    ]);

    $result = $channel->sendTest($user);

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('access token');
});

test('send test refuses before hitting the network when the token is missing', function () {
    Http::fake();

    $user = User::factory()->create();
    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(['access_token' => '']),
    ]);

    expect($channel->sendTest($user)['ok'])->toBeFalse();

    Http::assertNothingSent();
});

test('operational messages deliver rather than silently no-op', function () {
    Http::fake(['api.intercom.io/*' => Http::response(['id' => '1'], 200)]);

    $user = User::factory()->create();
    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(),
    ]);

    $channel->sendOperationalMessage('Disk almost full', 'web-1 is at 92%.', 'https://dply.test/servers/1', 'Open in Dply');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.intercom.io/messages'
            && str_contains((string) $request['body'], 'Disk almost full')
            && str_contains((string) $request['body'], 'web-1 is at 92%.')
            && str_contains((string) $request['body'], 'https://dply.test/servers/1');
    });
});

test('an email channel sends subject and template', function () {
    Http::fake(['api.intercom.io/*' => Http::response(['id' => '1'], 200)]);

    $user = User::factory()->create();
    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig([
            'message_type' => 'email',
            'template' => 'personal',
            'subject' => 'dply alert',
        ]),
    ]);

    $channel->sendOperationalMessage('Disk almost full', 'web-1 is at 92%.');

    Http::assertSent(function ($request) {
        return $request['message_type'] === 'email'
            && $request['template'] === 'personal'
            && $request['subject'] === 'Disk almost full';
    });
});

test('routeNotificationForIntercom mirrors the configured recipient', function () {
    $user = User::factory()->create();

    expect($user->routeNotificationForIntercom())->toBeFalse();

    $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(['recipient_type' => NotificationChannel::INTERCOM_TO_CONTACT_ID, 'recipient' => 'abc123']),
    ]);

    expect($user->fresh()->routeNotificationForIntercom())->toBe(['type' => 'contact', 'id' => 'abc123']);
});

function intercomTestEvent(): NotificationEvent
{
    return NotificationEvent::query()->create([
        'event_key' => 'server.insights_alerts',
        'title' => 'Disk almost full',
        'body' => 'web-1 is at 92%.',
        'severity' => 'warning',
        'category' => 'server',
        'supports_in_app' => true,
        'supports_email' => false,
        'supports_webhook' => true,
        'occurred_at' => now(),
    ]);
}

test('a mail notification gains an intercom leg only once a channel exists', function () {
    $user = User::factory()->create();
    $notification = new SshKeyRotationDueNotification(intercomTestEvent());

    expect($notification->via($user))->toBe(['mail']);

    $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(),
    ]);

    expect($notification->via($user->fresh()))->toBe(['mail', 'intercom']);
});

test('toIntercom derives its body from the notification mail message', function () {
    $user = User::factory()->create();
    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(),
    ]);

    $notification = new SshKeyRotationDueNotification(intercomTestEvent());
    $message = $notification->toIntercom($user->fresh());
    $payload = $message->toArray();

    expect($payload['from'])->toBe(['type' => 'admin', 'id' => '394051']);
    expect($payload['to'])->toBe(['type' => 'user', 'email' => 'ops@acme.test']);
    expect($payload['body'])->toBeString()->not->toBe('');
    // The credential comes off the channel, not from config/services.
    expect($message->getToken())->toBe($channel->config['access_token']);
});

test('the universal event notification deliberately has no intercom leg', function () {
    // The event behind it has already been fanned out to every subscribed
    // channel by NotificationRoutingResolver — an intercom leg here would
    // deliver the same event twice.
    $user = User::factory()->create();
    $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_INTERCOM,
        'label' => 'Ops inbox',
        'config' => intercomConfig(),
    ]);

    expect((new UniversalEventNotification(intercomTestEvent()))->via($user->fresh()))
        ->not->toContain('intercom');
});
