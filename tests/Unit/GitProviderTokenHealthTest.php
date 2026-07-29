<?php

declare(strict_types=1);

namespace Tests\Unit\GitProviderTokenHealthTest;

use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\SourceControl\Services\GitProviderTokenHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeToken(array $overrides = []): GitProviderToken
{
    return GitProviderToken::create(array_merge([
        'user_id' => User::factory()->create()->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'nickname' => 'dev',
        'access_token' => 'github_pat_x',
    ], $overrides));
}

test('a healthy GitHub token is stamped with the real expiry from the response header', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'dev'], 200, [
            'github-authentication-token-expiration' => '2026-08-01 03:41:41 UTC',
        ]),
    ]);

    $token = makeToken();
    $ok = app(GitProviderTokenHealth::class)->refresh($token);
    $token->refresh();

    expect($ok)->toBeTrue();
    expect($token->validation_error)->toBeNull();
    expect($token->last_validated_at)->not->toBeNull();
    expect($token->expires_at?->toDateString())->toBe('2026-08-01');
});

test('a rejected GitHub token records the provider error', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $token = makeToken();
    $ok = app(GitProviderTokenHealth::class)->refresh($token);
    $token->refresh();

    expect($ok)->toBeFalse();
    expect($token->validation_error)->toContain('401');
    expect($token->validation_error)->toContain('Bad credentials');
});

test('a fine-grained PAT without profile read falls back to the repos endpoint', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['message' => 'Resource not accessible'], 403),
        'api.github.com/user/repos*' => Http::response([['owner' => ['id' => 1, 'login' => 'dev']]], 200),
    ]);

    $token = makeToken();

    expect(app(GitProviderTokenHealth::class)->refresh($token))->toBeTrue();
    expect($token->refresh()->validation_error)->toBeNull();
});

test('a network failure neither passes nor fails the token', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $token = makeToken();
    $ok = app(GitProviderTokenHealth::class)->refresh($token);
    $token->refresh();

    expect($ok)->toBeNull();
    expect($token->validation_error)->toBeNull();
    expect($token->last_validated_at)->toBeNull();
});

test('a healthy re-check clears a previous validation error', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'dev'], 200),
    ]);

    $token = makeToken(['validation_error' => 'HTTP 401 — Bad credentials']);

    expect(app(GitProviderTokenHealth::class)->refresh($token))->toBeTrue();
    expect($token->refresh()->validation_error)->toBeNull();
});

test('OAuth social accounts validate through the same service', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $account = SocialAccount::create([
        'user_id' => User::factory()->create()->id,
        'provider' => 'github',
        'provider_id' => 'gh-2',
        'nickname' => 'dev',
        'access_token' => 'gho_dead',
    ]);

    expect(app(GitProviderTokenHealth::class)->refresh($account))->toBeFalse();
    expect($account->refresh()->validation_error)->toContain('401');
});
