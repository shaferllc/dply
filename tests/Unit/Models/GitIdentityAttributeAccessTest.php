<?php

declare(strict_types=1);

namespace Tests\Unit\Models\GitIdentityAttributeAccessTest;

use App\Models\GitProviderToken;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('social account id and provider resolve without recursion', function () {
    $user = User::factory()->create();
    $account = SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'access_token' => 'gho_test',
    ]);

    expect($account->id())->toBe((string) $account->getKey())
        ->and($account->id)->toBe($account->getKey())
        ->and($account->provider())->toBe('github')
        ->and($account->provider)->toBe('github')
        ->and($account->accessToken())->toBe('gho_test');
});

test('git provider token id and provider resolve without recursion', function () {
    $user = User::factory()->create();
    $pat = GitProviderToken::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'access_token' => 'gho_pat_test',
    ]);

    expect($pat->id())->toBe((string) $pat->getKey())
        ->and($pat->provider())->toBe('github')
        ->and($pat->accessToken())->toBe('gho_pat_test');
});
