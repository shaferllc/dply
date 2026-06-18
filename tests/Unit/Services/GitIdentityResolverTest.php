<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitIdentityResolverTest;

use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('forId resolves an OAuth account by ULID', function () {
    $user = User::factory()->create();
    $account = SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'nickname' => 'dev',
        'access_token' => 'gho_oauthtoken',
    ]);

    $resolver = new GitIdentityResolver;
    $identity = $resolver->forId($user, (string) $account->id);

    expect($identity)->not->toBeNull();
    expect($identity->provider())->toBe('github');
    expect($identity->accessToken())->toBe('gho_oauthtoken');
    expect($identity->kind())->toBe('oauth');
});

test('forId resolves a PAT by ULID', function () {
    $user = User::factory()->create();
    $pat = GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'gitlab',
        'provider_id' => 'gl-1',
        'nickname' => 'machine',
        'access_token' => 'glpat-secret',
    ]);

    $resolver = new GitIdentityResolver;
    $identity = $resolver->forId($user, (string) $pat->id);

    expect($identity)->not->toBeNull();
    expect($identity->provider())->toBe('gitlab');
    expect($identity->accessToken())->toBe('glpat-secret');
    expect($identity->kind())->toBe('pat');
});

test('forId returns null when the id belongs to a different user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $pat = GitProviderToken::create([
        'user_id' => $other->id,
        'provider' => 'github',
        'access_token' => 'gho_secret',
    ]);

    expect((new GitIdentityResolver)->forId($user, (string) $pat->id))->toBeNull();
});

test('forUserProvider prefers OAuth over PAT for the same provider', function () {
    $user = User::factory()->create();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'access_token' => 'gho_oauthtoken',
    ]);
    GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'access_token' => 'gho_pattoken',
    ]);

    $identity = (new GitIdentityResolver)->forUserProvider($user, 'github');
    expect($identity?->kind())->toBe('oauth');
});

test('forUserProvider falls back to PAT when no OAuth exists', function () {
    $user = User::factory()->create();
    GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'bitbucket',
        'access_token' => 'bb_pat_token',
    ]);

    $identity = (new GitIdentityResolver)->forUserProvider($user, 'bitbucket');
    expect($identity?->kind())->toBe('pat');
    expect($identity?->accessToken())->toBe('bb_pat_token');
});

test('allForUser returns both OAuth and PAT identities', function () {
    $user = User::factory()->create();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'access_token' => 'gho_oauthtoken',
    ]);
    GitProviderToken::create([
        'user_id' => $user->id,
        'provider' => 'gitlab',
        'access_token' => 'glpat_secret',
    ]);

    $identities = (new GitIdentityResolver)->allForUser($user);
    expect($identities)->toHaveCount(2);
    $kinds = array_map(fn ($i) => $i->kind(), $identities);
    expect($kinds)->toContain('oauth');
    expect($kinds)->toContain('pat');
});
