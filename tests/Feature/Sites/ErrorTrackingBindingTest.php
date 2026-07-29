<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\ErrorTrackingBindingTest;

use App\Models\ErrorTrackingCredential;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Modules\Deploy\Services\SiteBindingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: Site}
 */
function errorTrackingFixture(): array
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

test('attaching Sentry injects the DSN and marks the binding configured', function () {
    [, , $site] = errorTrackingFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'error_tracking', [
        'provider' => 'sentry',
        'dsn' => 'https://publickey@o0.ingest.sentry.io/123',
    ]);

    expect($binding->type)->toBe('error_tracking');
    expect($binding->status)->toBe(SiteBinding::STATUS_CONFIGURED);
    expect($binding->connectionEnv())->toMatchArray([
        'SENTRY_LARAVEL_DSN' => 'https://publickey@o0.ingest.sentry.io/123',
    ]);
});

test('attaching Bugsnag injects the API key', function () {
    [, , $site] = errorTrackingFixture();

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'error_tracking', [
        'provider' => 'bugsnag',
        'api_key' => 'bugsnag-key-123',
    ]);

    expect($binding->connectionEnv())->toMatchArray(['BUGSNAG_API_KEY' => 'bugsnag-key-123']);
    expect($binding->config['provider'])->toBe('bugsnag');
});

test('a Sentry DSN that is not a URL is rejected', function () {
    [, , $site] = errorTrackingFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'error_tracking', [
        'provider' => 'sentry',
        'dsn' => 'not-a-dsn',
    ]);
})->throws(InvalidArgumentException::class);

test('an unknown error tracking provider is rejected', function () {
    [, , $site] = errorTrackingFixture();

    app(SiteBindingManager::class)->attachExisting($site, 'error_tracking', [
        'provider' => 'rollbar',
        'dsn' => 'https://x',
    ]);
})->throws(InvalidArgumentException::class);

test('save_credential stores a reusable org credential', function () {
    [$user, $org, $site] = errorTrackingFixture();
    $this->actingAs($user);

    app(SiteBindingManager::class)->attachExisting($site, 'error_tracking', [
        'provider' => 'flare',
        'key' => 'flare-key-abc',
        'save_credential' => true,
        'credential_name' => 'Team Flare',
    ]);

    $cred = ErrorTrackingCredential::query()
        ->where('organization_id', $org->id)
        ->where('provider', 'flare')
        ->first();

    expect($cred)->not->toBeNull();
    expect($cred->name)->toBe('Team Flare');
    expect($cred->credentials)->toMatchArray(['key' => 'flare-key-abc']);
});

test('switching providers re-points the single error_tracking binding and drops stale keys', function () {
    [, , $site] = errorTrackingFixture();
    $manager = app(SiteBindingManager::class);

    $manager->attachExisting($site, 'error_tracking', [
        'provider' => 'sentry',
        'dsn' => 'https://publickey@o0.ingest.sentry.io/123',
    ]);

    $binding = $manager->attachExisting($site->fresh(), 'error_tracking', [
        'provider' => 'bugsnag',
        'api_key' => 'bugsnag-key-123',
    ]);

    // updateOrCreate keeps one row per site+type.
    expect(SiteBinding::query()->where('site_id', $site->id)->where('type', 'error_tracking')->count())->toBe(1);
    expect($binding->connectionEnv())->toHaveKey('BUGSNAG_API_KEY');
    expect($binding->connectionEnv())->not->toHaveKey('SENTRY_LARAVEL_DSN');
});

test('attaching adopts the provider key out of the loose .env', function () {
    [, , $site] = errorTrackingFixture();

    $site->forceFill([
        'env_file_content' => "APP_NAME=Acme\nSENTRY_LARAVEL_DSN=https://stale@example.com/9\n",
        'env_cache_origin' => 'local-edit',
    ])->save();

    app(SiteBindingManager::class)->attachExisting($site, 'error_tracking', [
        'provider' => 'sentry',
        'dsn' => 'https://publickey@o0.ingest.sentry.io/123',
    ]);

    // The loose, now-managed key is stripped so the binding value wins.
    expect((string) $site->fresh()->env_file_content)->not->toContain('SENTRY_LARAVEL_DSN');
});
