<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceControlOAuthReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_stores_a_same_app_return_path(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://github.test/login/oauth'));
        Socialite::shouldReceive('driver')->andReturn($driver);

        $this->actingAs(User::factory()->create())
            ->get('/auth/github/redirect?return_to=/servers/abc/sites/xyz/repository');

        $this->assertSame('/servers/abc/sites/xyz/repository', session('oauth_return_url'));
    }

    #[DataProvider('maliciousReturnTargets')]
    public function test_redirect_rejects_an_unsafe_return_target(string $returnTo): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('scopes')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://github.test/login/oauth'));
        Socialite::shouldReceive('driver')->andReturn($driver);

        $this->actingAs(User::factory()->create())
            ->get('/auth/github/redirect?return_to='.urlencode($returnTo));

        $this->assertNull(session('oauth_return_url'));
    }

    /** @return array<string, array{0: string}> */
    public static function maliciousReturnTargets(): array
    {
        return [
            'protocol-relative' => ['//evil.example'],
            'absolute url' => ['https://evil.example/phish'],
            'no leading slash' => ['evil.example'],
            'backslash trick' => ['/\\evil.example'],
        ];
    }

    public function test_callback_returns_to_the_stored_page_after_linking(): void
    {
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
