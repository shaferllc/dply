<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\IntegrationBindingsTest;

use App\Models\AiCredential;
use App\Models\CaptchaCredential;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SmsCredential;
use App\Models\User;
use App\Services\Deploy\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

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

test('switching AI providers drops the previous provider key', function () {
    [, , $site] = integrationFixture();
    $manager = app(SiteBindingManager::class);

    $manager->attachExisting($site, 'ai', ['provider' => 'openai', 'api_key' => 'sk-1']);
    $binding = $manager->attachExisting($site->fresh(), 'ai', ['provider' => 'anthropic', 'api_key' => 'ant-1']);

    expect(SiteBinding::query()->where('site_id', $site->id)->where('type', 'ai')->count())->toBe(1);
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
