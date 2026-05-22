<?php


namespace Tests\Feature\ProviderOAuthTest;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('digital ocean oauth redirect requires auth', function () {
    config([
        'services.digitalocean_oauth.client_id' => 'do-client',
        'services.digitalocean_oauth.client_secret' => 'do-secret',
    ]);

    $response = $this->get(route('credentials.oauth.digitalocean.redirect'));

    $response->assertRedirect(route('login', absolute: false));
});

test('digital ocean oauth redirect goes to digital ocean when configured', function () {
    config([
        'services.digitalocean_oauth.client_id' => 'do-client',
        'services.digitalocean_oauth.client_secret' => 'do-secret',
    ]);

    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('credentials.oauth.digitalocean.redirect'));

    $response->assertRedirect();
    $target = $response->headers->get('Location');
    expect($target)->toStartWith('https://cloud.digitalocean.com/v1/oauth/authorize?');
    $this->assertStringContainsString('client_id=do-client', $target);
    $this->assertStringContainsString('scope=read+write', $target);
});

test('digital ocean oauth redirect redirects back when not configured', function () {
    config([
        'services.digitalocean_oauth.client_id' => '',
        'services.digitalocean_oauth.client_secret' => '',
    ]);

    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('credentials.oauth.digitalocean.redirect'));

    $response->assertRedirect(route('credentials.index', ['provider' => 'digitalocean'], false));
    $response->assertSessionHas('error');
});

test('digital ocean oauth callback creates credential', function () {
    config([
        'services.digitalocean_oauth.client_id' => 'do-client',
        'services.digitalocean_oauth.client_secret' => 'do-secret',
    ]);

    $user = ownerWithOrg();
    $org = $user->currentOrganization();
    expect($org)->not->toBeNull();

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
            'credentials_oauth_digitalocean_'.$nonce => $oauthState,
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
    expect($cred)->not->toBeNull();
    expect($cred->credentials['auth'] ?? null)->toBe('oauth');
    expect($cred->credentials['access_token'] ?? null)->toBe('oauth-access-test');
});

test('digital ocean oauth callback rejects invalid state', function () {
    config([
        'services.digitalocean_oauth.client_id' => 'do-client',
        'services.digitalocean_oauth.client_secret' => 'do-secret',
    ]);

    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('credentials.oauth.digitalocean.callback', [
        'code' => 'x',
        'state' => Str::random(40),
    ]));

    $response->assertRedirect(route('credentials.index', ['provider' => 'digitalocean'], false));
    $response->assertSessionHas('error');
    $this->assertDatabaseCount('provider_credentials', 0);
});