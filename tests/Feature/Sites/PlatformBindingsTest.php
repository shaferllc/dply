<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\PlatformBindingsTest;

use App\Models\Organization;
use App\Models\PaymentCredential;
use App\Models\SearchCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: Site}
 */
function platformFixture(bool $withHostname = false): array
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
        'meta' => $withHostname
            ? ['testing_hostname' => ['hostname' => 'app.on-dply.site', 'status' => 'ready']]
            : [],
    ]);

    return [$user, $org, $site];
}

// ---- Search -------------------------------------------------------------

test('attaching Algolia injects the Scout driver and keys', function () {
    [, , $site] = platformFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'search', [
        'provider' => 'algolia',
        'app_id' => 'APPID',
        'secret' => 'admin-key',
    ]);

    expect($binding->type)->toBe('search');
    expect($binding->connectionEnv())->toMatchArray([
        'SCOUT_DRIVER' => 'algolia',
        'ALGOLIA_APP_ID' => 'APPID',
        'ALGOLIA_SECRET' => 'admin-key',
    ]);
});

test('attaching Typesense defaults port and protocol', function () {
    [, , $site] = platformFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'search', [
        'provider' => 'typesense',
        'host' => '127.0.0.1',
        'api_key' => 'ts-key',
    ]);

    expect($binding->connectionEnv())->toMatchArray([
        'SCOUT_DRIVER' => 'typesense',
        'TYPESENSE_HOST' => '127.0.0.1',
        'TYPESENSE_API_KEY' => 'ts-key',
        'TYPESENSE_PORT' => '8108',
        'TYPESENSE_PROTOCOL' => 'http',
    ]);
});

test('Meilisearch requires a host', function () {
    [, , $site] = platformFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'search', ['provider' => 'meilisearch', 'key' => 'k']);
})->throws(InvalidArgumentException::class);

test('switching search driver drops the previous driver keys', function () {
    [, , $site] = platformFixture();
    $manager = app(SiteBindingManager::class);

    $manager->attachExisting($site, 'search', ['provider' => 'algolia', 'app_id' => 'A', 'secret' => 'S']);
    $binding = $manager->attachExisting($site->fresh(), 'search', ['provider' => 'meilisearch', 'host' => 'http://127.0.0.1:7700']);

    expect($binding->connectionEnv())->toHaveKey('MEILISEARCH_HOST');
    expect($binding->connectionEnv())->not->toHaveKey('ALGOLIA_APP_ID');
});

// ---- Payments -----------------------------------------------------------

test('attaching Stripe injects keys, the Vite mirror and computes the webhook URL', function () {
    [, , $site] = platformFixture(withHostname: true);

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'payments', [
        'provider' => 'stripe',
        'key' => 'pk_live_1',
        'secret' => 'sk_live_1',
    ]);

    expect($binding->connectionEnv())->toMatchArray([
        'STRIPE_KEY' => 'pk_live_1',
        'STRIPE_SECRET' => 'sk_live_1',
        'VITE_STRIPE_KEY' => 'pk_live_1',
    ]);
    expect($binding->config['webhook_url'])->toBe('https://app.on-dply.site/stripe/webhook');
});

test('Stripe requires key and secret', function () {
    [, , $site] = platformFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'payments', ['provider' => 'stripe', 'key' => 'pk_only']);
})->throws(InvalidArgumentException::class);

test('payments without a hostname still attaches but has no webhook URL', function () {
    [, , $site] = platformFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'payments', [
        'provider' => 'paddle',
        'api_key' => 'pad-1',
        'client_side_token' => 'cst-1',
    ]);

    expect($binding->connectionEnv())->toHaveKey('PADDLE_API_KEY');
    expect($binding->config)->not->toHaveKey('webhook_url');
});

// ---- OAuth --------------------------------------------------------------

test('attaching GitHub OAuth auto-fills the redirect URL from the site hostname', function () {
    [, , $site] = platformFixture(withHostname: true);

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'oauth', [
        'provider' => 'github',
        'client_id' => 'gh-id',
        'client_secret' => 'gh-secret',
    ]);

    expect($binding->connectionEnv())->toMatchArray([
        'GITHUB_CLIENT_ID' => 'gh-id',
        'GITHUB_CLIENT_SECRET' => 'gh-secret',
        'GITHUB_REDIRECT_URI' => 'https://app.on-dply.site/auth/github/callback',
    ]);
});

test('an explicit OAuth redirect override wins over the derived one', function () {
    [, , $site] = platformFixture(withHostname: true);

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'oauth', [
        'provider' => 'google',
        'client_id' => 'g-id',
        'client_secret' => 'g-secret',
        'redirect' => 'https://custom.example.com/callback',
    ]);

    expect($binding->connectionEnv()['GOOGLE_REDIRECT_URI'])->toBe('https://custom.example.com/callback');
});

test('OAuth without a hostname or override is rejected', function () {
    [, , $site] = platformFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'oauth', [
        'provider' => 'github',
        'client_id' => 'gh-id',
        'client_secret' => 'gh-secret',
    ]);
})->throws(InvalidArgumentException::class);

test('OAuth requires client id and secret', function () {
    [, , $site] = platformFixture(withHostname: true);

    app(SiteBindingManager::class)->attachExisting($site, 'oauth', ['provider' => 'github', 'client_id' => 'only-id']);
})->throws(InvalidArgumentException::class);

// ---- Saved credentials --------------------------------------------------

test('save_credential stores reusable org credentials for search and payments', function () {
    [$user, $org, $site] = platformFixture(withHostname: true);
    $this->actingAs($user);
    $manager = app(SiteBindingManager::class);

    $manager->attachExisting($site, 'search', [
        'provider' => 'algolia', 'app_id' => 'A', 'secret' => 'S', 'save_credential' => true, 'credential_name' => 'Team Algolia',
    ]);
    $manager->attachExisting($site->fresh(), 'payments', [
        'provider' => 'stripe', 'key' => 'pk', 'secret' => 'sk', 'save_credential' => true,
    ]);

    expect(SearchCredential::query()->where('organization_id', $org->id)->where('provider', 'algolia')->first()?->name)->toBe('Team Algolia');
    expect(PaymentCredential::query()->where('organization_id', $org->id)->where('provider', 'stripe')->first()?->credentials)->toMatchArray(['key' => 'pk', 'secret' => 'sk']);
});

test('attaching search adopts the loose SCOUT_DRIVER out of the .env', function () {
    [, , $site] = platformFixture();

    $site->forceFill([
        'env_file_content' => "APP_NAME=Acme\nSCOUT_DRIVER=database\n",
        'env_cache_origin' => 'local-edit',
    ])->save();

    app(SiteBindingManager::class)->attachExisting($site, 'search', [
        'provider' => 'algolia', 'app_id' => 'A', 'secret' => 'S',
    ]);

    expect((string) $site->fresh()->env_file_content)->not->toContain('SCOUT_DRIVER');
});
