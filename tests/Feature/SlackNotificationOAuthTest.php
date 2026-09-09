<?php

namespace Tests\Feature\SlackNotificationOAuthTest;

use App\Livewire\Settings\NotificationChannels as ProfileNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\SlackInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.slack.client_id', 'client-id');
    config()->set('services.slack.client_secret', 'client-secret');
    Cache::flush();
});

/** Put a valid, unexpired OAuth state in the session, as the redirect leg would. */
function slackState(User $user): string
{
    $nonce = Str::random(40);
    session()->put('slack_oauth_'.$nonce, [
        'user_id' => (string) $user->id,
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'return_to' => '/profile/notification-channels',
        'issued_at' => now()->timestamp,
    ]);

    return $nonce;
}

function fakeInstallation(User $user): SlackInstallation
{
    return SlackInstallation::query()->create([
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'team_id' => 'T123',
        'team_name' => 'Acme',
        'bot_user_id' => 'U9',
        'scopes' => 'chat:write,chat:write.public,channels:read',
        'credentials' => ['bot_token' => 'xoxb-test'],
    ]);
}

test('add to slack redirects to slack authorize with bot scopes', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('notifications.oauth.slack.redirect'));

    $response->assertRedirectContains('https://slack.com/oauth/v2/authorize');
    $target = $response->headers->get('Location');
    expect($target)->toContain('chat%3Awrite')
        ->and($target)->toContain('chat%3Awrite.public')
        ->and($target)->toContain('client_id=client-id');
});

test('add to slack is unavailable when no slack app is configured', function () {
    config()->set('services.slack.client_id', null);
    config()->set('services.slack.client_secret', null);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.oauth.slack.redirect'))
        ->assertRedirect('/profile/notification-channels')
        ->assertSessionHas('error');
});

test('callback stores an encrypted bot token for the workspace', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-real-token',
            'scope' => 'chat:write,chat:write.public,channels:read,groups:read,team:read',
            'bot_user_id' => 'U42',
            'team' => ['id' => 'T777', 'name' => 'Acme Inc'],
        ]),
    ]);

    $user = User::factory()->create();
    $nonce = slackState($user);

    $response = $this->actingAs($user)
        ->get(route('notifications.oauth.slack.callback', ['code' => 'abc', 'state' => $nonce]))
        ->assertSessionHas('success');

    expect($response->headers->get('Location'))->toContain('/profile/notification-channels');

    $installation = SlackInstallation::query()->firstOrFail();
    expect($installation->team_id)->toBe('T777')
        ->and($installation->team_name)->toBe('Acme Inc')
        ->and($installation->botToken())->toBe('xoxb-real-token')
        ->and($installation->owner_id)->toBe((string) $user->id);

    // The cast must actually encrypt — a bot token can post anywhere in the workspace.
    $raw = \DB::table('slack_installations')->where('id', $installation->id)->value('credentials');
    expect($raw)->not->toContain('xoxb-real-token');
});

test('connecting from a server tab returns to that tab with the modal marker', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-real-token',
            'team' => ['id' => 'T777', 'name' => 'Acme Inc'],
        ]),
    ]);

    $user = User::factory()->create();
    $nonce = Str::random(40);
    session()->put('slack_oauth_'.$nonce, [
        'user_id' => (string) $user->id,
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'return_to' => '/servers/01ABC/notifications?tab=alerts',
        'issued_at' => now()->timestamp,
    ]);

    $response = $this->actingAs($user)
        ->get(route('notifications.oauth.slack.callback', ['code' => 'abc', 'state' => $nonce]));

    $installation = SlackInstallation::query()->firstOrFail();
    $target = $response->headers->get('Location');

    // Back to the exact tab, existing query intact, marker appended.
    expect($target)->toContain('/servers/01ABC/notifications')
        ->and($target)->toContain('tab=alerts')
        ->and($target)->toContain('slack_connected='.$installation->id);
});

test('a livewire endpoint return_to is refused rather than followed', function () {
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-real-token',
            'team' => ['id' => 'T777', 'name' => 'Acme Inc'],
        ]),
    ]);

    $user = User::factory()->create();
    $nonce = Str::random(40);
    session()->put('slack_oauth_'.$nonce, [
        'user_id' => (string) $user->id,
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        // What request()->path() yields when the link is rendered mid-Livewire-update.
        'return_to' => '/livewire-0050c2c6/update',
        'issued_at' => now()->timestamp,
    ]);

    $response = $this->actingAs($user)
        ->get(route('notifications.oauth.slack.callback', ['code' => 'abc', 'state' => $nonce]));

    // That path is POST-only; following it would 405.
    $target = $response->headers->get('Location');
    expect($target)->not->toContain('livewire')
        ->and($target)->toContain('/profile/notification-channels');
});

test('returning from slack reopens the channel modal with the workspace selected', function () {
    $user = User::factory()->create();
    $installation = fakeInstallation($user);

    Http::fake(['slack.com/api/conversations.list' => Http::response([
        'ok' => true,
        'channels' => [['id' => 'C111', 'name' => 'alerts', 'is_private' => false, 'is_member' => true]],
    ])]);

    Livewire::withQueryParams(['slack_connected' => (string) $installation->id])
        ->actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->assertSet('new_type', NotificationChannel::TYPE_SLACK)
        ->assertSet('new_slack_mode', 'oauth')
        ->assertSet('new_slack_installation_id', (string) $installation->id);
});

test('another owner\'s installation id in the url does not preselect anything', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $theirs = fakeInstallation($stranger);

    Livewire::withQueryParams(['slack_connected' => (string) $theirs->id])
        ->actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->assertSet('new_slack_installation_id', '');
});

test('reconnecting the same workspace refreshes the token in place', function () {
    $user = User::factory()->create();
    $existing = fakeInstallation($user);

    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response([
            'ok' => true,
            'access_token' => 'xoxb-rotated',
            'scope' => 'chat:write',
            'team' => ['id' => 'T123', 'name' => 'Acme'],
        ]),
    ]);

    $nonce = slackState($user);
    $this->actingAs($user)
        ->get(route('notifications.oauth.slack.callback', ['code' => 'abc', 'state' => $nonce]))
        ->assertSessionHas('success');

    expect(SlackInstallation::query()->count())->toBe(1)
        ->and(SlackInstallation::query()->find($existing->id)->botToken())->toBe('xoxb-rotated');
});

test('callback rejects a token exchange slack refused', function () {
    // Slack answers 200 OK with ok:false — the status code alone proves nothing.
    Http::fake([
        'slack.com/api/oauth.v2.access' => Http::response(['ok' => false, 'error' => 'invalid_code'], 200),
    ]);

    $user = User::factory()->create();
    $nonce = slackState($user);

    $this->actingAs($user)
        ->get(route('notifications.oauth.slack.callback', ['code' => 'bad', 'state' => $nonce]))
        ->assertSessionHas('error');

    expect(SlackInstallation::query()->count())->toBe(0);
});

test('callback rejects an unknown state nonce', function () {
    Http::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.oauth.slack.callback', ['code' => 'abc', 'state' => Str::random(40)]))
        ->assertSessionHas('error');

    Http::assertNothingSent();
    expect(SlackInstallation::query()->count())->toBe(0);
});

test('callback rejects state minted for a different user', function () {
    Http::fake();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $nonce = slackState($owner);

    // Same session, different signed-in user — the actor check must catch it.
    $this->actingAs($intruder)
        ->get(route('notifications.oauth.slack.callback', ['code' => 'abc', 'state' => $nonce]))
        ->assertRedirect(route('login'));

    expect(SlackInstallation::query()->count())->toBe(0);
});

test('creating a slack channel from a connected workspace stores the channel id', function () {
    $user = User::factory()->create();
    $installation = fakeInstallation($user);

    Http::fake([
        'slack.com/api/conversations.list' => Http::response([
            'ok' => true,
            'channels' => [
                ['id' => 'C111', 'name' => 'alerts', 'is_private' => false, 'is_member' => true],
                ['id' => 'C222', 'name' => 'ops', 'is_private' => true, 'is_member' => false],
            ],
        ]),
    ]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_SLACK)
        ->set('new_label', 'Deploys')
        ->set('new_slack_mode', 'oauth')
        ->set('new_slack_installation_id', (string) $installation->id)
        ->set('new_slack_channel_id', 'C111')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->firstOrFail();
    expect($channel->usesSlackOauth())->toBeTrue()
        ->and($channel->config['channel_id'])->toBe('C111')
        ->and($channel->config['channel'])->toBe('#alerts')
        ->and($channel->config['installation_id'])->toBe((string) $installation->id);
});

test('oauth slack channel requires a channel selection', function () {
    $user = User::factory()->create();
    $installation = fakeInstallation($user);

    Http::fake(['slack.com/api/conversations.list' => Http::response(['ok' => true, 'channels' => []])]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_SLACK)
        ->set('new_label', 'Deploys')
        ->set('new_slack_mode', 'oauth')
        ->set('new_slack_installation_id', (string) $installation->id)
        ->set('new_slack_channel_id', '')
        ->call('createChannel')
        ->assertHasErrors('new_slack_channel_id');

    expect(NotificationChannel::query()->count())->toBe(0);
});

test('pasted webhook slack channels still work', function () {
    config()->set('services.slack.client_id', null);
    config()->set('services.slack.client_secret', null);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_SLACK)
        ->set('new_label', 'Legacy')
        ->set('new_slack_webhook_url', 'https://hooks.slack.com/services/T/B/X')
        ->set('new_slack_channel', 'general')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->firstOrFail();
    expect($channel->usesSlackOauth())->toBeFalse()
        ->and($channel->config['webhook_url'])->toBe('https://hooks.slack.com/services/T/B/X');
});

test('test message on an oauth channel posts via chat.postMessage', function () {
    $user = User::factory()->create();
    $installation = fakeInstallation($user);

    Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1.2'])]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::SLACK_AUTH_OAUTH,
            'installation_id' => (string) $installation->id,
            'channel_id' => 'C111',
            'channel' => '#alerts',
        ],
    ]);

    expect($channel->sendTest($user)['ok'])->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'C111'
        && $request->hasHeader('Authorization', 'Bearer xoxb-test'));
});

test('a slack error body is surfaced as a failure even on http 200', function () {
    $user = User::factory()->create();
    $installation = fakeInstallation($user);

    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => false, 'error' => 'channel_not_found'], 200),
    ]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::SLACK_AUTH_OAUTH,
            'installation_id' => (string) $installation->id,
            'channel_id' => 'CGONE',
        ],
    ]);

    $result = $channel->sendTest($user);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('no longer exists');
});

test('a channel whose workspace was disconnected reports a fixable error', function () {
    $user = User::factory()->create();

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Orphan',
        'config' => [
            'auth' => NotificationChannel::SLACK_AUTH_OAUTH,
            'installation_id' => '01JQQQQQQQQQQQQQQQQQQQQQQQ',
            'channel_id' => 'C111',
        ],
    ]);

    $result = $channel->sendTest($user);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('disconnected');
});

test('not_in_channel triggers a join and retry', function () {
    $user = User::factory()->create();
    $installation = fakeInstallation($user);

    $posts = 0;
    Http::fake([
        'slack.com/api/chat.postMessage' => function () use (&$posts) {
            $posts++;

            return $posts === 1
                ? Http::response(['ok' => false, 'error' => 'not_in_channel'], 200)
                : Http::response(['ok' => true, 'ts' => '1.2'], 200);
        },
        'slack.com/api/conversations.join' => Http::response(['ok' => true]),
    ]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::SLACK_AUTH_OAUTH,
            'installation_id' => (string) $installation->id,
            'channel_id' => 'C111',
        ],
    ]);

    expect($channel->sendTest($user)['ok'])->toBeTrue()
        ->and($posts)->toBe(2);
});

test('disconnecting a workspace leaves its channels in place', function () {
    $user = User::factory()->create();
    $installation = fakeInstallation($user);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::SLACK_AUTH_OAUTH,
            'installation_id' => (string) $installation->id,
            'channel_id' => 'C111',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('disconnectSlackWorkspace', (string) $installation->id);

    expect(SlackInstallation::query()->count())->toBe(0)
        ->and(NotificationChannel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

test('a workspace belonging to another user cannot be selected or disconnected', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $theirs = fakeInstallation($stranger);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('disconnectSlackWorkspace', (string) $theirs->id);

    expect(SlackInstallation::query()->whereKey($theirs->id)->exists())->toBeTrue();
});
