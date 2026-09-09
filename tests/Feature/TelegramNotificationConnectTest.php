<?php

namespace Tests\Feature\TelegramNotificationConnectTest;

use App\Livewire\Settings\NotificationChannels as ProfileNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\TelegramConnectToken;
use App\Models\TelegramInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'bot-token');
    config()->set('services.telegram.webhook_secret', 'super-secret');
    Cache::flush();
});

function telegramUpdate(string $text, array $chat = []): array
{
    return [
        'update_id' => 1,
        'message' => [
            'message_id' => 10,
            'text' => $text,
            'chat' => array_merge(['id' => -1001234567890, 'title' => 'Acme Ops', 'type' => 'supergroup'], $chat),
        ],
    ];
}

function postTelegramWebhook(array $payload, ?string $secret = 'super-secret')
{
    $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

    return test()->postJson('/hooks/telegram', $payload, $headers);
}

function issueToken(User $user): TelegramConnectToken
{
    return TelegramConnectToken::issueFor($user, $user);
}

test('a start command redeems the token and records the chat', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    $user = User::factory()->create();
    $token = issueToken($user);

    postTelegramWebhook(telegramUpdate('/start '.$token->token))->assertOk();

    $installation = TelegramInstallation::query()->firstOrFail();
    expect($installation->chat_id)->toBe('-1001234567890')
        ->and($installation->chat_title)->toBe('Acme Ops')
        ->and($installation->chat_type)->toBe('supergroup')
        ->and($installation->owner_id)->toBe((string) $user->id);

    expect($token->fresh()->consumed_at)->not->toBeNull();
});

test('the group form of the command is recognised', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    $user = User::factory()->create();
    $token = issueToken($user);

    // Telegram sends /start@thebot <payload> when a bot is added to a group —
    // the main path, and the one a naive '/start ' prefix check would miss.
    postTelegramWebhook(telegramUpdate('/start@dply_bot '.$token->token))->assertOk();

    expect(TelegramInstallation::query()->count())->toBe(1);
});

test('a wrong secret token is rejected without touching the payload', function () {
    Http::fake();

    $user = User::factory()->create();
    $token = issueToken($user);

    postTelegramWebhook(telegramUpdate('/start '.$token->token), 'not-the-secret')->assertForbidden();

    expect(TelegramInstallation::query()->count())->toBe(0)
        ->and($token->fresh()->consumed_at)->toBeNull();
    Http::assertNothingSent();
});

test('a missing secret header is rejected', function () {
    $user = User::factory()->create();
    $token = issueToken($user);

    postTelegramWebhook(telegramUpdate('/start '.$token->token), null)->assertForbidden();

    expect(TelegramInstallation::query()->count())->toBe(0);
});

test('the endpoint refuses to run when no secret is configured', function () {
    // An unset secret must never mean "accept anything".
    config()->set('services.telegram.webhook_secret', null);

    $user = User::factory()->create();
    $token = issueToken($user);

    postTelegramWebhook(telegramUpdate('/start '.$token->token), '')->assertForbidden();

    expect(TelegramInstallation::query()->count())->toBe(0);
});

test('a token cannot be redeemed twice', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    $user = User::factory()->create();
    $token = issueToken($user);

    postTelegramWebhook(telegramUpdate('/start '.$token->token))->assertOk();
    // A second chat presenting the same link must not attach itself too.
    postTelegramWebhook(telegramUpdate('/start '.$token->token, ['id' => -100999, 'title' => 'Someone Else']))->assertOk();

    expect(TelegramInstallation::query()->count())->toBe(1)
        ->and(TelegramInstallation::query()->first()->chat_title)->toBe('Acme Ops');
});

test('an expired token is not redeemable', function () {
    Http::fake();

    $user = User::factory()->create();
    $token = issueToken($user);
    $token->forceFill(['expires_at' => now()->subMinute()])->save();

    postTelegramWebhook(telegramUpdate('/start '.$token->token))->assertOk();

    expect(TelegramInstallation::query()->count())->toBe(0);
});

test('ordinary chatter is ignored', function () {
    Http::fake();

    postTelegramWebhook(telegramUpdate('just a normal message'))->assertOk();
    postTelegramWebhook(telegramUpdate('/start'))->assertOk();

    expect(TelegramInstallation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('an unknown payload does not create anything', function () {
    Http::fake();

    postTelegramWebhook(telegramUpdate('/start totally-made-up-token'))->assertOk();

    expect(TelegramInstallation::query()->count())->toBe(0);
});

test('a private chat is titled from the user name', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    $user = User::factory()->create();
    $token = issueToken($user);

    postTelegramWebhook(telegramUpdate('/start '.$token->token, [
        'id' => 555,
        'type' => 'private',
        'title' => null,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]))->assertOk();

    $installation = TelegramInstallation::query()->firstOrFail();
    expect($installation->chat_title)->toBe('Ada Lovelace')
        ->and($installation->isDirectMessage())->toBeTrue();
});

test('starting a connect issues a deep link with the group picker', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'dply_bot']])]);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('startTelegramConnect');

    $token = TelegramConnectToken::query()->firstOrFail();

    // startgroup, not start — that is what makes Telegram show its group picker.
    $component->assertSet('telegramConnectLink', 'https://t.me/dply_bot?startgroup='.$token->token)
        ->assertDispatched('open-external');

    expect($token->owner_id)->toBe((string) $user->id);
});

test('polling picks up the connection once the webhook lands', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'dply_bot']])]);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('startTelegramConnect');

    $token = TelegramConnectToken::query()->firstOrFail();

    // Still outstanding: the form keeps waiting.
    $component->call('pollTelegramConnect')->assertSet('telegramConnectToken', $token->token);

    postTelegramWebhook(telegramUpdate('/start '.$token->token))->assertOk();

    $installation = TelegramInstallation::query()->firstOrFail();

    $component->call('pollTelegramConnect')
        ->assertSet('telegramConnectToken', '')
        ->assertSet('new_telegram_mode', 'connected')
        ->assertSet('new_telegram_installation_id', (string) $installation->id);
});

test('polling gives up on an expired link', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'dply_bot']])]);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('startTelegramConnect');

    TelegramConnectToken::query()->firstOrFail()->forceFill(['expires_at' => now()->subMinute()])->save();

    $component->call('pollTelegramConnect')->assertSet('telegramConnectToken', '');
});

test('creating a channel from a connected chat stores no bot token', function () {
    $user = User::factory()->create();
    $installation = TelegramInstallation::query()->create([
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'chat_id' => '-100123',
        'chat_title' => 'Acme Ops',
        'chat_type' => 'supergroup',
    ]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_TELEGRAM)
        ->set('new_label', 'Deploys')
        ->set('new_telegram_mode', 'connected')
        ->set('new_telegram_installation_id', (string) $installation->id)
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->firstOrFail();
    expect($channel->usesTelegramConnected())->toBeTrue()
        ->and($channel->config['chat_id'])->toBe('-100123')
        // The bot token is deployment config; copying it per channel would mean a
        // rotation silently broke every existing channel.
        ->and($channel->config)->not->toHaveKey('bot_token');
});

test('pasted bot token telegram channels still work', function () {
    config()->set('services.telegram.bot_token', null);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('openCreateChannelModal')
        ->set('new_type', NotificationChannel::TYPE_TELEGRAM)
        ->set('new_label', 'Legacy')
        ->set('new_telegram_bot_token', '123:ABC')
        ->set('new_telegram_chat_id', '-100999')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->firstOrFail();
    expect($channel->usesTelegramConnected())->toBeFalse()
        ->and($channel->config['bot_token'])->toBe('123:ABC');
});

test('test message on a connected channel posts through the deployment bot', function () {
    $user = User::factory()->create();
    $installation = TelegramInstallation::query()->create([
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'chat_id' => '-100123',
        'chat_title' => 'Acme Ops',
        'chat_type' => 'supergroup',
    ]);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_TELEGRAM,
        'label' => 'Deploys',
        'config' => [
            'auth' => NotificationChannel::TELEGRAM_AUTH_CONNECTED,
            'installation_id' => (string) $installation->id,
            'chat_id' => '-100123',
        ],
    ]);

    expect($channel->sendTest($user)['ok'])->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && $request['chat_id'] === '-100123');
});

test('a channel error description is surfaced usefully', function () {
    $user = User::factory()->create();
    $installation = TelegramInstallation::query()->create([
        'owner_type' => User::class,
        'owner_id' => (string) $user->id,
        'chat_id' => '-100123',
        'chat_title' => 'Acme News',
        'chat_type' => 'channel',
    ]);

    Http::fake(['api.telegram.org/*' => Http::response([
        'ok' => false,
        'description' => 'Bad Request: need administrator rights in the channel chat',
    ], 400)]);

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_TELEGRAM,
        'label' => 'News',
        'config' => [
            'auth' => NotificationChannel::TELEGRAM_AUTH_CONNECTED,
            'installation_id' => (string) $installation->id,
            'chat_id' => '-100123',
        ],
    ]);

    $result = $channel->sendTest($user);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('administrator');
});

test('a channel whose chat was disconnected reports a fixable error', function () {
    $user = User::factory()->create();

    $channel = $user->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_TELEGRAM,
        'label' => 'Orphan',
        'config' => [
            'auth' => NotificationChannel::TELEGRAM_AUTH_CONNECTED,
            'installation_id' => '01JQQQQQQQQQQQQQQQQQQQQQQQ',
            'chat_id' => '-100123',
        ],
    ]);

    $result = $channel->sendTest($user);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('disconnected');
});

test('another owner\'s chat cannot be disconnected', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $theirs = TelegramInstallation::query()->create([
        'owner_type' => User::class,
        'owner_id' => (string) $stranger->id,
        'chat_id' => '-100777',
        'chat_title' => 'Theirs',
        'chat_type' => 'group',
    ]);

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('disconnectTelegramChat', (string) $theirs->id);

    expect(TelegramInstallation::query()->whereKey($theirs->id)->exists())->toBeTrue();
});
