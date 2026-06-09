<?php

declare(strict_types=1);

namespace Tests\Feature\SourceControlOAuthReturnTest;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;

uses(RefreshDatabase::class);

test('redirect stores a same app return path', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect('https://github.test/login/oauth'));
    Socialite::shouldReceive('driver')->andReturn($driver);

    $this->actingAs(User::factory()->create())
        ->get('/auth/github/redirect?return_to=/servers/abc/sites/xyz/repository');

    expect(session('oauth_return_url'))->toBe('/servers/abc/sites/xyz/repository');
});
test('redirect rejects an unsafe return target', function (string $returnTo) {
    $driver = Mockery::mock();
    $driver->shouldReceive('scopes')->andReturnSelf();
    $driver->shouldReceive('redirect')->andReturn(redirect('https://github.test/login/oauth'));
    Socialite::shouldReceive('driver')->andReturn($driver);

    $this->actingAs(User::factory()->create())
        ->get('/auth/github/redirect?return_to='.urlencode($returnTo));

    expect(session('oauth_return_url'))->toBeNull();
})->with('maliciousReturnTargets');
/** @return array<string, array{0: string}> */
dataset('maliciousReturnTargets', function () {
    return [
        'protocol-relative' => ['//evil.example'],
        'absolute url' => ['https://evil.example/phish'],
        'no leading slash' => ['evil.example'],
        'backslash trick' => ['/\\evil.example'],
    ];
});
test('callback returns to the stored page after linking', function () {
    $user = User::factory()->create();

    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getId')->andReturn('gh-123');
    $socialUser->shouldReceive('getNickname')->andReturn('octocat');
    $socialUser->shouldReceive('getName')->andReturn('Octo Cat');
    $socialUser->token = 'gho_token';
    $socialUser->refreshToken = null;

    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($socialUser);
    Socialite::shouldReceive('driver')->andReturn($driver);

    $response = $this->actingAs($user)
        ->withSession([
            'oauth_intent' => 'link',
            'oauth_return_url' => '/servers/abc/sites/xyz/repository',
        ])
        ->get('/auth/github/callback');

    $response->assertredirect('/servers/abc/sites/xyz/repository');
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-123',
    ]);
});
afterEach(function () {
    Mockery::close();
});
