<?php

namespace Tests\Feature\DiscordNotificationOAuthTest;

use App\Livewire\Settings\NotificationChannels as ProfileNotificationChannels;
use App\Models\DiscordInstallation;
use App\Models\DiscordPermissions;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.discord.client_id', 'client-id');
    config()->set('services.discord.client_secret', 'client-secret');
    config()->set('services.discord.bot_token', 'bot-token');
    Cache::flush();
});

function discordState(User $user, string $returnTo = '/profile/notification-channels'): string
{
    $nonce = Str::random(40);
    session()->put('discord_oauth_'.$nonce, [
        'user_id' => (string) $user->id,
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'return_to' => $returnTo,
        'issued_at' => now()->timestamp,
    ]);

    return $nonce;
}

function fakeGuild(User $user): DiscordInstallation
{
    return DiscordInstallation::query()->create([
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'guild_id' => '99887766',
        'guild_name' => 'Acme HQ',
        'permissions' => (string) DiscordPermissions::REQUIRED,
    ]);
}

test('add to discord redirects with the bot scope and minimal permissions', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('notifications.oauth.discord.redirect'));

    $response->assertRedirectContains('https://discord.com/oauth2/authorize');
    $target = $response->headers->get('Location');
    expect($target)->toContain('scope=bot')
        ->and($target)->toContain('permissions='.DiscordPermissions::REQUIRED)
        ->and($target)->toContain('response_type=code');
});

test('add to discord stays hidden when no bot token is configured', function () {
    // Client id and secret alone are not enough: the bot token does not come out
    // of the OAuth exchange, so the flow would connect a dead server.
    config()->set('services.discord.bot_token', null);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.oauth.discord.redirect'))
        ->assertRedirect('/profile/notification-channels')
        ->assertSessionHas('error');
});

test('callback records the guild the bot was added to', function () {
    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response([
            'access_token' => 'ignored-user-token',
            'guild' => ['id' => '99887766', 'name' => 'Acme HQ', 'permissions' => '3072'],
        ]),
    ]);

    $user = User::factory()->create();
    $nonce = discordState($user);

    $this->actingAs($user)
        ->get(route('notifications.oauth.discord.callback', ['code' => 'abc', 'state' => $nonce]))
        ->assertSessionHas('success');

    $installation = DiscordInstallation::query()->firstOrFail();
    expect($installation->guild_id)->toBe('99887766')
        ->and($installation->guild_name)->toBe('Acme HQ')
        ->and($installation->canPost())->toBeTrue()
        ->and($installation->owner_id)->toBe((string) $user->id);
});

test('callback rejects an exchange that returned no guild', function () {
    // Happens when consent completes without a server actually being picked.
    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'x']),
    ]);

    $user = User::factory()->create();
    $nonce = discordState($user);

    $this->actingAs($user)
        ->get(route('notifications.oauth.discord.callback', ['code' => 'abc', 'state' => $nonce]))
        ->assertSessionHas('error');

    expect(DiscordInstallation::query()->count())->toBe(0);
});

test('reconnecting the same guild updates in place', function () {
    $user = User::factory()->create();
    $existing = fakeGuild($user);

    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response([
            'guild' => ['id' => '99887766', 'name' => 'Acme HQ Renamed', 'permissions' => '3072'],
        ]),
    ]);

    $nonce = discordState($user);
    $this->actingAs($user)
        ->get(route('notifications.oauth.discord.callback', ['code' => 'abc', 'state' => $nonce]))
        ->assertSessionHas('success');

    expect(DiscordInstallation::query()->count())->toBe(1)
        ->and(DiscordInstallation::query()->find($existing->id)->guild_name)->toBe('Acme HQ Renamed');
});

test('connecting from a server tab returns there with the modal marker', function () {
    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response([
            'guild' => ['id' => '99887766', 'name' => 'Acme HQ'],
        ]),
    ]);

    $user = User::factory()->create();
    $nonce = discordState($user, '/servers/01ABC/notifications?tab=alerts');

    $response = $this->actingAs($user)
        ->get(route('notifications.oauth.discord.callback', ['code' => 'abc', 'state' => $nonce]));

    $installation = DiscordInstallation::query()->firstOrFail();
    $target = $response->headers->get('Location');

    expect($target)->toContain('/servers/01ABC/notifications')
        ->and($target)->toContain('tab=alerts')
        ->and($target)->toContain('discord_connected='.$installation->id);
});

test('a livewire endpoint return_to is refused rather than followed', function () {
    Http::fake([
        'discord.com/api/v10/oauth2/token' => Http::response([
            'guild' => ['id' => '99887766', 'name' => 'Acme HQ'],
        ]),
    ]);

    $user = User::factory()->create();
    $nonce = discordState($user, '/livewire-0050c2c6/update');

    $response = $this->actingAs($user)
        ->get(route('notifications.oauth.discord.callback', ['code' => 'abc', 'state' => $nonce]));

    expect($response->headers->get('Location'))->not->toContain('livewire');
});

test('callback rejects state minted for a different user', function () {
    Http::fake();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $nonce = discordState($owner);

    $this->actingAs($intruder)
        ->get(route('notifications.oauth.discord.callback', ['code' => 'abc', 'state' => $nonce]))
        ->assertRedirect(route('login'));

    expect(DiscordInstallation::query()->count())->toBe(0);
});

test('only postable text channels reach the picker', function () {
    $user = User::factory()->create();
    $installation = fakeGuild($user);

    Http::fake([
        'discord.com/api/v10/guilds/*/channels' => Http::response([
            ['id' => '1', 'name' => 'general', 'type' => 0, 'position' => 1],
            ['id' => '2', 'name' => 'Voice Chat', 'type' => 2, 'position' => 0],   // voice
            ['id' => '3', 'name' => 'Text Category', 'type' => 4, 'position' => 0], // category
            ['id' => '4', 'name' => 'announcements', 'type' => 5, 'position' => 0],
        ]),
    ]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_DISCORD)
        ->set('new_label', 'Deploys')
        ->set('new_discord_mode', 'oauth')
        ->set('new_discord_installation_id', (string) $installation->id)
        ->set('new_discord_channel_id', '1')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->firstOrFail();
    expect($channel->usesDiscordOauth())->toBeTrue()
        ->and($channel->config['channel_id'])->toBe('1')
        ->and($channel->config['channel'])->toBe('#general')
        ->and($channel->config['guild_id'])->toBe('99887766');
});

test('oauth discord channel requires a channel selection', function () {
    $user = User::factory()->create();
    $installation = fakeGuild($user);

    Http::fake(['discord.com/api/v10/guilds/*/channels' => Http::response([])]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_DISCORD)
        ->set('new_label', 'Deploys')
        ->set('new_discord_mode', 'oauth')
        ->set('new_discord_installation_id', (string) $installation->id)
        ->set('new_discord_channel_id', '')
        ->call('createChannel')
        ->assertHasErrors('new_discord_channel_id');
});

test('pasted webhook discord channels still work', function () {
    config()->set('services.discord.client_id', null);
    config()->set('services.discord.bot_token', null);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_DISCORD)
        ->set('new_label', 'Legacy')
        ->set('new_discord_webhook_url', 'https://discord.com/api/webhooks/1/x')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->firstOrFail();
    expect($channel->usesDiscordOauth())->toBeFalse()
        ->and($channel->config['webhook_url'])->toBe('https://discord.com/api/webhooks/1/x');
});

test('test message on an oauth channel posts via the bot token', function () {
    $user = User::factory()->create();
    $installation = fakeGuild($user);

    Http::fake(['discord.com/api/v10/channels/*/messages' => Http::response(['id' => '5'], 200)]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_DISCORD,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::DISCORD_AUTH_OAUTH,
            'installation_id' => (string) $installation->id,
            'channel_id' => '1',
            'channel' => '#general',
        ],
    ]);

    expect($channel->sendTest($user)['ok'])->toBeTrue();

    // "Bot <token>", not Bearer — Discord 401s on the wrong prefix.
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bot bot-token'));
});

test('a channel-level permission denial is explained in terms of discord', function () {
    $user = User::factory()->create();
    $installation = fakeGuild($user);

    Http::fake([
        'discord.com/api/v10/channels/*/messages' => Http::response(['message' => 'Missing Access', 'code' => 50001], 403),
    ]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_DISCORD,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::DISCORD_AUTH_OAUTH,
            'installation_id' => (string) $installation->id,
            'channel_id' => '1',
        ],
    ]);

    $result = $channel->sendTest($user);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('permissions');
});

test('a channel whose guild was disconnected reports a fixable error', function () {
    $user = User::factory()->create();

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_DISCORD,
        'label' => 'Orphan',
        'config' => [
            'auth' => NotificationChannel::DISCORD_AUTH_OAUTH,
            'installation_id' => '01JQQQQQQQQQQQQQQQQQQQQQQQ',
            'channel_id' => '1',
        ],
    ]);

    $result = $channel->sendTest($user);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('disconnected');
});

test('returning from discord reopens the modal with the guild selected', function () {
    $user = User::factory()->create();
    $installation = fakeGuild($user);

    Http::fake(['discord.com/api/v10/guilds/*/channels' => Http::response([
        ['id' => '1', 'name' => 'general', 'type' => 0, 'position' => 0],
    ])]);

    Livewire::withQueryParams(['discord_connected' => (string) $installation->id])
        ->actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->assertSet('new_type', NotificationChannel::TYPE_DISCORD)
        ->assertSet('new_discord_mode', 'oauth')
        ->assertSet('new_discord_installation_id', (string) $installation->id);
});

test('disconnecting a guild leaves its channels in place', function () {
    $user = User::factory()->create();
    $installation = fakeGuild($user);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_DISCORD,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::DISCORD_AUTH_OAUTH,
            'installation_id' => (string) $installation->id,
            'channel_id' => '1',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('disconnectDiscordGuild', (string) $installation->id);

    expect(DiscordInstallation::query()->count())->toBe(0)
        ->and(NotificationChannel::query()->whereKey($channel->id)->exists())->toBeTrue();
});

test('another owner\'s guild cannot be disconnected', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $theirs = fakeGuild($stranger);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('disconnectDiscordGuild', (string) $theirs->id);

    expect(DiscordInstallation::query()->whereKey($theirs->id)->exists())->toBeTrue();
});
