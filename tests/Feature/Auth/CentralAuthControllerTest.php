<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Dply\Core\Auth\CentralOAuthClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_creates_a_default_workspace_for_new_users(): void
    {
        config()->set('dply_auth.enabled', true);

        $handler = new MockHandler([
            new Response(200, [], json_encode([
                'access_token' => 'access-token',
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'id' => 'central-user-1',
                'name' => 'Central User',
                'email' => 'central@example.com',
                'email_verified_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new CentralOAuthClient(
            'https://auth.example.test',
            'client-id',
            'client-secret',
            'https://dply.test/oauth/callback',
            new Client(['handler' => HandlerStack::create($handler), 'http_errors' => false])
        );

        $this->app->instance(CentralOAuthClient::class, $client);

        $response = $this
            ->withSession([
                'dply_auth_oauth' => [
                    'verifier' => 'verifier-123',
                    'state' => 'state-123',
                ],
            ])
            ->get('/oauth/callback?'.http_build_query([
                'code' => 'auth-code',
                'state' => 'state-123',
            ]));

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'central@example.com')->first();
        $this->assertNotNull($user);

        $org = Organization::query()->where('name', "Central User's Workspace")->first();
        $this->assertNotNull($org);
        $this->assertTrue($org->hasMember($user));
    }
}
