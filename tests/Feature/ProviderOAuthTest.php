<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_digital_ocean_oauth_redirect_requires_auth(): void
    {
        config([
            'services.digitalocean_oauth.client_id' => 'do-client',
            'services.digitalocean_oauth.client_secret' => 'do-secret',
        ]);

        $response = $this->get(route('credentials.oauth.digitalocean.redirect'));

        $response->assertRedirect(route('login', absolute: false));
    }

    public function test_digital_ocean_oauth_redirect_goes_to_digital_ocean_when_configured(): void
    {
        config([
            'services.digitalocean_oauth.client_id' => 'do-client',
            'services.digitalocean_oauth.client_secret' => 'do-secret',
        ]);

        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('credentials.oauth.digitalocean.redirect'));

        $response->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith('https://cloud.digitalocean.com/v1/oauth/authorize?', $target);
        $this->assertStringContainsString('client_id=do-client', $target);
        $this->assertStringContainsString('scope=read+write', $target);
    }

    public function test_digital_ocean_oauth_redirect_redirects_back_when_not_configured(): void
    {
        config([
            'services.digitalocean_oauth.client_id' => '',
            'services.digitalocean_oauth.client_secret' => '',
        ]);

        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('credentials.oauth.digitalocean.redirect'));

        $response->assertRedirect(route('credentials.index', ['provider' => 'digitalocean'], false));
        $response->assertSessionHas('error');
    }

    public function test_digital_ocean_oauth_callback_creates_credential(): void
    {
        config([
            'services.digitalocean_oauth.client_id' => 'do-client',
            'services.digitalocean_oauth.client_secret' => 'do-secret',
        ]);

        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->assertNotNull($org);

        $nonce = Str::random(40);
        $oauthState = [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'label' => 'OAuth test',
            'issued_at' => now()->timestamp,
        ];

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'cloud.digitalocean.com/v1/oauth/token')) {
                return Http::response([
                    'access_token' => 'oauth-access-test',
                    'refresh_token' => 'refresh-test',
                    'expires_in' => 7200,
                    'token_type' => 'bearer',
                ], 200);
            }
            if (str_contains($request->url(), 'api.digitalocean.com/v2/droplets')) {
                return Http::response(['droplets' => []], 200);
            }

            return Http::response('unexpected URL in test: '.$request->url(), 500);
        });

        $response = $this->actingAs($user)
            ->withSession([
                'current_organization_id' => $org->id,
                'credentials_oauth:digitalocean:'.$nonce => $oauthState,
            ])
            ->get(route('credentials.oauth.digitalocean.callback', [
                'code' => 'auth-code-xyz',
                'state' => $nonce,
            ]));

        $response->assertRedirect(route('organizations.credentials', ['organization' => $org, 'provider' => 'digitalocean'], false));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('provider_credentials', [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean',
            'name' => 'OAuth test',
        ]);

        $cred = ProviderCredential::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($cred);
        $this->assertSame('oauth', $cred->credentials['auth'] ?? null);
        $this->assertSame('oauth-access-test', $cred->credentials['access_token'] ?? null);
    }

    public function test_digital_ocean_oauth_callback_rejects_invalid_state(): void
    {
        config([
            'services.digitalocean_oauth.client_id' => 'do-client',
            'services.digitalocean_oauth.client_secret' => 'do-secret',
        ]);

        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('credentials.oauth.digitalocean.callback', [
            'code' => 'x',
            'state' => Str::random(40),
        ]));

        $response->assertRedirect(route('credentials.index', ['provider' => 'digitalocean'], false));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('provider_credentials', 0);
    }
}
