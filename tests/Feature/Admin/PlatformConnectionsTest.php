<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\PlatformConnectionsTest;

use App\Http\Controllers\Notifications\SlackOAuthController;
use App\Livewire\Admin\Connections;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Modules\Notifications\Services\PlatformNotificationApps;
use App\Modules\Notifications\Services\TelegramBotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.slack.client_id', null);
    config()->set('services.slack.client_secret', null);
    config()->set('services.discord.client_id', null);
    config()->set('services.discord.client_secret', null);
    config()->set('services.discord.bot_token', null);
    config()->set('services.telegram.bot_token', null);
    config()->set('services.telegram.webhook_secret', null);
});

test('guest cannot open platform connections', function () {
    $this->get(route('admin.connections'))->assertRedirect(route('login', absolute: false));
});

test('authenticated user can open platform connections in testing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.connections'))
        ->assertOk()
        ->assertSee(__('Connections'))
        ->assertSee(__('Save Slack'))
        ->assertSee(__('Save Discord'))
        ->assertSee(__('Save Telegram'));
});

test('saving slack from admin makes add to slack live', function () {
    $user = User::factory()->create();

    expect(SlackOAuthController::configured())->toBeFalse();

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('slack.client_id', 'slack-app-id')
        ->set('slack.client_secret', 'slack-app-secret')
        ->set('slack.redirect', 'https://example.test/notifications/oauth/slack/callback')
        ->call('saveSlack')
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    expect(SlackOAuthController::configured())->toBeTrue()
        ->and(PlatformConnection::query()->where('provider', 'slack')->exists())->toBeTrue()
        ->and(PlatformNotificationApps::slack()['client_secret'])->toBe('slack-app-secret');
});

test('saving slack again with a blank secret keeps the stored secret', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('slack.client_id', 'slack-app-id')
        ->set('slack.client_secret', 'original-secret')
        ->call('saveSlack');

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('slack.client_id', 'slack-app-id')
        ->set('slack.client_secret', '')
        ->call('saveSlack')
        ->assertHasNoErrors();

    expect(PlatformNotificationApps::slack()['client_secret'])->toBe('original-secret');
});

test('testing telegram calls getMe and records success', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['username' => 'dply_bot', 'id' => 1],
        ]),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('telegram.bot_token', '123:test-token')
        ->set('telegram.webhook_secret', 'hook-secret')
        ->call('testTelegram')
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    expect(TelegramBotClient::botConfigured())->toBeTrue()
        ->and(PlatformConnection::query()->where('provider', 'telegram')->value('last_error'))->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/getMe'));
});

test('registering the telegram webhook posts setWebhook', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Connections::class)
        ->set('telegram.bot_token', '123:test-token')
        ->set('telegram.webhook_secret', 'hook-secret')
        ->set('telegram.webhook_url', 'https://example.com/hooks/telegram')
        ->call('registerTelegramWebhook')
        ->assertHasNoErrors()
        ->assertDispatched('notify');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/setWebhook')
        && $request['url'] === 'https://example.com/hooks/telegram'
        && $request['secret_token'] === 'hook-secret');
});
