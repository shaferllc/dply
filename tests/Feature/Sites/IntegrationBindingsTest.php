<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\IntegrationBindingsTest;

use App\Livewire\Sites\ResourceMap;
use App\Models\AiCredential;
use App\Models\CaptchaCredential;
use App\Models\ConnectedAppCredential;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use App\Models\User;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: Site}
 */
function integrationFixture(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $org, $site];
}

test('attaching OpenAI injects the API key and organization', function () {
    [, , $site] = integrationFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'ai', [
        'provider' => 'openai',
        'api_key' => 'sk-test-123',
        'organization' => 'org-abc',
    ]);

    expect($binding->type)->toBe('ai');
    expect($binding->status)->toBe(SiteBinding::STATUS_CONFIGURED);
    expect($binding->connectionEnv())->toMatchArray([
        'OPENAI_API_KEY' => 'sk-test-123',
        'OPENAI_ORGANIZATION' => 'org-abc',
    ]);
});

test('an AI binding without a key is rejected', function () {
    [, , $site] = integrationFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'ai', ['provider' => 'anthropic']);
})->throws(InvalidArgumentException::class);

test('switching AI providers keeps both bindings (keys do not collide)', function () {
    [, , $site] = integrationFixture();
    $manager = app(SiteBindingManager::class);

    $manager->attachExisting($site, 'ai', ['provider' => 'openai', 'api_key' => 'sk-1']);
    $binding = $manager->attachExisting($site->fresh(), 'ai', ['provider' => 'anthropic', 'api_key' => 'ant-1']);

    // Multi-instance AI bindings: OpenAI + Anthropic coexist (separate key namespaces).
    expect(SiteBinding::query()->where('site_id', $site->id)->where('type', 'ai')->count())->toBe(2);
    expect($binding->connectionEnv())->toHaveKey('ANTHROPIC_API_KEY');
    expect($binding->connectionEnv())->not->toHaveKey('OPENAI_API_KEY');
});

test('attaching Turnstile injects keys plus the public VITE mirror', function () {
    [, , $site] = integrationFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'captcha', [
        'provider' => 'turnstile',
        'site_key' => '0xpublic',
        'secret_key' => '0xsecret',
    ]);

    expect($binding->connectionEnv())->toMatchArray([
        'TURNSTILE_SITE_KEY' => '0xpublic',
        'TURNSTILE_SECRET_KEY' => '0xsecret',
        'VITE_TURNSTILE_SITE_KEY' => '0xpublic',
    ]);
});

test('captcha requires both site and secret keys', function () {
    [, , $site] = integrationFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'captcha', [
        'provider' => 'recaptcha',
        'site_key' => 'only-site',
    ]);
})->throws(InvalidArgumentException::class);

test('attaching Twilio injects sid, token and from', function () {
    [, , $site] = integrationFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'sms', [
        'provider' => 'twilio',
        'sid' => 'AC123',
        'auth_token' => 'tok-123',
        'from' => '+15551234567',
    ]);

    expect($binding->connectionEnv())->toMatchArray([
        'TWILIO_SID' => 'AC123',
        'TWILIO_AUTH_TOKEN' => 'tok-123',
        'TWILIO_FROM' => '+15551234567',
    ]);
});

test('Twilio requires sid and token', function () {
    [, , $site] = integrationFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'sms', [
        'provider' => 'twilio',
        'sid' => 'AC123',
    ]);
})->throws(InvalidArgumentException::class);

test('save_credential stores reusable org credentials for each integration', function () {
    [$user, $org, $site] = integrationFixture();
    $this->actingAs($user);
    $manager = app(SiteBindingManager::class);

    $manager->attachExisting($site, 'ai', [
        'provider' => 'groq', 'api_key' => 'gsk-1', 'save_credential' => true, 'credential_name' => 'Team Groq',
    ]);
    $manager->attachExisting($site->fresh(), 'captcha', [
        'provider' => 'hcaptcha', 'site_key' => 'sk', 'secret_key' => 'se', 'save_credential' => true,
    ]);
    $manager->attachExisting($site->fresh(), 'sms', [
        'provider' => 'vonage', 'key' => 'vk', 'secret' => 'vs', 'from' => 'Acme', 'save_credential' => true,
    ]);

    expect(AiCredential::query()->where('organization_id', $org->id)->where('provider', 'groq')->first()?->name)->toBe('Team Groq');
    expect(CaptchaCredential::query()->where('organization_id', $org->id)->where('provider', 'hcaptcha')->exists())->toBeTrue();
    expect(SmsCredential::query()->where('organization_id', $org->id)->where('provider', 'vonage')->first()?->credentials)->toMatchArray(['key' => 'vk', 'secret' => 'vs', 'from' => 'Acme']);
});

test('attaching adopts the provider key out of the loose .env', function () {
    [, , $site] = integrationFixture();

    $site->forceFill([
        'env_file_content' => "APP_NAME=Acme\nOPENAI_API_KEY=sk-stale\n",
        'env_cache_origin' => 'local-edit',
    ])->save();

    app(SiteBindingManager::class)->attachExisting($site, 'ai', [
        'provider' => 'openai',
        'api_key' => 'sk-new',
    ]);

    expect((string) $site->fresh()->env_file_content)->not->toContain('OPENAI_API_KEY');
});

test('attaching slack injects bot token and webhook', function () {
    [, , $site] = integrationFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'connected_app', [
        'provider' => 'slack',
        'bot_token' => 'xoxb-test',
        'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        'channel' => '#ops',
    ]);

    expect($binding->type)->toBe('connected_app')
        ->and($binding->name)->toBe('Slack')
        ->and($binding->connectionEnv())->toMatchArray([
            'SLACK_BOT_USER_OAUTH_TOKEN' => 'xoxb-test',
            'SLACK_BOT_TOKEN' => 'xoxb-test',
            'SLACK_WEBHOOK_URL' => 'https://hooks.slack.com/services/T/B/X',
            'SLACK_BOT_USER_DEFAULT_CHANNEL' => '#ops',
        ]);
});

test('slack requires a bot token or a webhook', function () {
    [, , $site] = integrationFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'connected_app', [
        'provider' => 'slack',
        'channel' => '#ops',
    ]);
})->throws(InvalidArgumentException::class);

test('google drive and slack coexist on one site', function () {
    [, , $site] = integrationFixture();
    $manager = app(SiteBindingManager::class);

    $manager->attachExisting($site, 'connected_app', [
        'provider' => 'slack',
        'bot_token' => 'xoxb-1',
    ]);
    $drive = $manager->attachExisting($site->fresh(), 'connected_app', [
        'provider' => 'google_drive',
        'client_id' => 'id.apps.googleusercontent.com',
        'client_secret' => 'secret',
        'refresh_token' => '1//refresh',
    ]);

    expect(SiteBinding::query()->where('site_id', $site->id)->where('type', 'connected_app')->count())->toBe(2)
        ->and($drive->connectionEnv())->toMatchArray([
            'GOOGLE_DRIVE_CLIENT_ID' => 'id.apps.googleusercontent.com',
            'GOOGLE_DRIVE_CLIENT_SECRET' => 'secret',
            'GOOGLE_DRIVE_REFRESH_TOKEN' => '1//refresh',
        ])
        ->and($drive->connectionEnv())->not->toHaveKey('SLACK_BOT_TOKEN');
});

test('save_credential stores a reusable connected app credential', function () {
    [$user, $org, $site] = integrationFixture();
    $this->actingAs($user);

    app(SiteBindingManager::class)->attachExisting($site, 'connected_app', [
        'provider' => 'discord',
        'bot_token' => 'discord-bot',
        'save_credential' => true,
        'credential_name' => 'Ops Discord',
    ]);

    expect(ConnectedAppCredential::query()->where('organization_id', $org->id)->where('provider', 'discord')->first()?->name)
        ->toBe('Ops Discord');
});

test('pasting slack env fills slack fields and ignores other keys', function () {
    [$user, , $site] = integrationFixture();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $site->server, 'site' => $site])
        ->call('openBindingModal', 'connected_app', 'attach')
        ->set('bindingForm.provider', 'slack')
        ->set('bindingForm.env_paste', "SLACK_BOT_TOKEN=xoxb-1\nSLACK_WEBHOOK_URL=https://hooks.slack.com/x\nDISCORD_BOT_TOKEN=nope\n")
        ->assertSet('bindingForm.bot_token', 'xoxb-1')
        ->assertSet('bindingForm.webhook_url', 'https://hooks.slack.com/x')
        ->assertSet('bindingForm.env_paste', '')
        ->assertSee(__('Filled 2 fields from the pasted .env.'));
});
