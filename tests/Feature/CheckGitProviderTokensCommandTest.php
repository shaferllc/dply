<?php

declare(strict_types=1);

namespace Tests\Feature\CheckGitProviderTokensCommandTest;

use App\Models\GitProviderToken;
use App\Models\NotificationInboxItem;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('a rejected PAT produces one inbox notification, deduped across runs', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $user = User::factory()->create();
    $token = GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'nickname' => 'dead',
        'access_token' => 'github_pat_dead',
    ]);

    $this->artisan('dply:git-tokens:check')->assertSuccessful();
    $this->artisan('dply:git-tokens:check')->assertSuccessful();

    $items = NotificationInboxItem::query()
        ->where('user_id', $user->id)
        ->where('metadata->kind', 'git_token_health')
        ->where('metadata->token_id', $token->id)
        ->get();

    expect($items)->toHaveCount(1);
    expect($items->first()->metadata['cta_label'])->toBe('Replace token');
    expect($items->first()->url)->toContain('/profile/source-control');
});

test('a token expiring within the warning window notifies even though it still validates', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'dev'], 200, [
            'github-authentication-token-expiration' => now()->addDays(3)->format('Y-m-d H:i:s \U\T\C'),
        ]),
    ]);

    $user = User::factory()->create();
    $token = GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-2',
        'nickname' => 'expiring',
        'access_token' => 'github_pat_expiring',
    ]);

    $this->artisan('dply:git-tokens:check')->assertSuccessful();

    expect($token->refresh()->expires_at)->not->toBeNull();
    expect(NotificationInboxItem::query()
        ->where('metadata->token_id', $token->id)
        ->exists())->toBeTrue();
});

test('a healthy token with a distant expiry does not notify', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'dev'], 200, [
            'github-authentication-token-expiration' => now()->addDays(30)->format('Y-m-d H:i:s \U\T\C'),
        ]),
    ]);

    $token = GitProviderToken::create([
        'user_id' => User::factory()->create()->id,
        'provider' => 'github',
        'provider_id' => 'gh-3',
        'nickname' => 'healthy',
        'access_token' => 'github_pat_ok',
    ]);

    $this->artisan('dply:git-tokens:check')->assertSuccessful();

    expect(NotificationInboxItem::query()->where('metadata->token_id', $token->id)->exists())->toBeFalse();
});

test('a rejected OAuth account is checked too and gets a reconnect CTA', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $user = User::factory()->create();
    $account = SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-4',
        'nickname' => 'oauth-dead',
        'access_token' => 'gho_dead',
    ]);

    $this->artisan('dply:git-tokens:check')->assertSuccessful();

    $item = NotificationInboxItem::query()->where('metadata->token_id', $account->id)->first();
    expect($item)->not->toBeNull();
    expect($item->metadata['cta_label'])->toBe('Reconnect account');
    expect($item->metadata['identity_type'])->toBe('oauth');
});

test('non-git social accounts are ignored', function () {
    Http::fake();

    SocialAccount::create([
        'user_id' => User::factory()->create()->id,
        'provider' => 'google',
        'provider_id' => 'g-1',
        'nickname' => 'login-only',
        'access_token' => 'ya29.x',
    ]);

    $this->artisan('dply:git-tokens:check')->assertSuccessful();

    Http::assertNothingSent();
});
