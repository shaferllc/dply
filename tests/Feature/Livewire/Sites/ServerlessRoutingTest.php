<?php


namespace Tests\Feature\Livewire\Sites\ServerlessRoutingTest;
use App\Http\Middleware\ResolveServerlessCustomDomain;
use App\Livewire\Sites\ServerlessRouting;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Serverless\ServerlessRoutingResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $serverlessMeta
 * @return array{0: User, 1: Server, 2: Site}
 */
function functionSite(array $serverlessMeta = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'git_repository_url' => 'acme/api',
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => array_merge([
                'runtime' => 'nodejs:20',
                'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/api',
                'proxy_slug' => 'acme-api',
            ], $serverlessMeta),
        ],
    ]);

    return [$user, $server, $site];
}

test('routing page renders with default hostname tab', function () {
    [$user, $server, $site] = functionSite();

    Livewire::actingAs($user)
        ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
        ->assertOk()
        ->assertSee('Hostname & DNS')
        ->assertSee('Custom domains')
        ->assertSee('Redirects')
        ->assertSee('Headers & CORS')
        ->assertSee('Invocation URLs')
        ->assertSet('tab', 'hostname');
});

test('add redirect persists to site meta', function () {
    [$user, $server, $site] = functionSite();

    Livewire::actingAs($user)
        ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
        ->set('newRedirectFrom', '/old-path')
        ->set('newRedirectTo', 'https://new.example.com/landing')
        ->set('newRedirectStatus', 301)
        ->call('addRedirect');

    $site->refresh();
    $redirects = $site->meta['serverless']['routing']['redirects'] ?? [];
    expect($redirects)->toHaveCount(1);
    expect($redirects[0]['from'])->toBe('/old-path');
    expect($redirects[0]['to'])->toBe('https://new.example.com/landing');
    expect($redirects[0]['status'])->toBe(301);
});

test('remove redirect drops the indexed entry', function () {
    [$user, $server, $site] = functionSite([
        'routing' => [
            'redirects' => [
                ['from' => '/a', 'to' => '/x', 'status' => 302, 'kind' => 'exact'],
                ['from' => '/b', 'to' => '/y', 'status' => 302, 'kind' => 'exact'],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
        ->call('removeRedirect', 0);

    $site->refresh();
    $redirects = $site->meta['serverless']['routing']['redirects'];
    expect($redirects)->toHaveCount(1);
    expect($redirects[0]['from'])->toBe('/b');
});

test('add header persists and rejects reserved names', function () {
    [$user, $server, $site] = functionSite();

    $component = Livewire::actingAs($user)
        ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
        ->set('newHeaderName', 'Content-Type')
        ->set('newHeaderValue', 'text/html')
        ->call('addHeader');

    $site->refresh();
    expect($site->meta['serverless']['routing']['headers'] ?? [])->toBeEmpty();

    $component
        ->set('newHeaderName', 'X-Frame-Options')
        ->set('newHeaderValue', 'DENY')
        ->call('addHeader');

    $site->refresh();
    $headers = $site->meta['serverless']['routing']['headers'];
    expect($headers)->toHaveCount(1);
    expect($headers[0]['name'])->toBe('X-Frame-Options');
    expect($headers[0]['value'])->toBe('DENY');
});

test('save cors persists normalised csv inputs', function () {
    [$user, $server, $site] = functionSite();

    Livewire::actingAs($user)
        ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
        ->set('corsEnabled', true)
        ->set('corsOrigins', 'https://app.acme.com, https://staging.acme.com')
        ->set('corsMethods', 'GET, POST')
        ->set('corsHeaders', 'Content-Type')
        ->set('corsAllowCredentials', true)
        ->set('corsMaxAge', 600)
        ->call('saveCors');

    $site->refresh();
    $cors = $site->meta['serverless']['routing']['cors'];
    expect($cors['enabled'])->toBeTrue();
    expect($cors['origins'])->toBe(['https://app.acme.com', 'https://staging.acme.com']);
    expect($cors['methods'])->toBe(['GET', 'POST']);
    expect($cors['headers'])->toBe(['Content-Type']);
    expect($cors['allow_credentials'])->toBeTrue();
    expect($cors['max_age'])->toBe(600);
});

test('resolver normalises meta state', function () {
    [, , $site] = functionSite([
        'routing' => [
            'redirects' => [
                ['from' => '/old', 'to' => '/new', 'status' => 301, 'kind' => 'exact'],
            ],
            'headers' => [
                ['name' => 'X-Frame-Options', 'value' => 'DENY'],
            ],
            'cors' => [
                'enabled' => true,
                'origins' => ['*'],
                'methods' => ['GET'],
                'headers' => ['Content-Type'],
                'allow_credentials' => false,
                'max_age' => 60,
            ],
            'custom_domains' => [
                ['hostname' => 'api.acme.com', 'mode' => 'auto', 'dns_status' => 'ready'],
            ],
        ],
    ]);

    $routing = app(ServerlessRoutingResolver::class)->forSite($site->fresh());

    expect($routing['redirects'][0]['from'])->toBe('/old');
    expect($routing['headers'][0]['name'])->toBe('X-Frame-Options');
    expect($routing['cors']['enabled'])->toBeTrue();
    expect($routing['custom_domains'][0]['hostname'])->toBe('api.acme.com');
    expect($routing['custom_domains'][0]['dns_status'])->toBe('ready');
});

test('proxy controller applies redirect rule before forwarding', function () {
    [, , $site] = functionSite([
        'routing' => [
            'redirects' => [
                ['from' => '/old', 'to' => 'https://new.example.com/here', 'status' => 308, 'kind' => 'exact'],
            ],
        ],
    ]);

    Http::fake();

    // assert no upstream call happens
    $response = $this->get('/fn/'.$site->meta['serverless']['proxy_slug'].'/old');

    $response->assertStatus(308);
    $response->assertRedirect('https://new.example.com/here');
    Http::assertNothingSent();
});

test('proxy controller emits cors preflight 204', function () {
    [, , $site] = functionSite([
        'routing' => [
            'cors' => [
                'enabled' => true,
                'origins' => ['https://app.acme.com'],
                'methods' => ['GET', 'POST'],
                'headers' => ['Content-Type', 'Authorization'],
                'allow_credentials' => true,
                'max_age' => 600,
            ],
        ],
    ]);

    Http::fake();

    $slug = $site->meta['serverless']['proxy_slug'];
    $response = $this->call(
        'OPTIONS',
        '/fn/'.$slug,
        [],
        [],
        [],
        $this->transformHeadersToServerVars([
            'Origin' => 'https://app.acme.com',
            'Access-Control-Request-Method' => 'POST',
        ]),
    );

    $response->assertNoContent(204);
    $response->assertHeader('Access-Control-Allow-Origin', 'https://app.acme.com');
    $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    $response->assertHeader('Access-Control-Max-Age', '600');
    Http::assertNothingSent();
});

test('proxy controller decorates response with configured headers and skips reserved', function () {
    [, , $site] = functionSite([
        'routing' => [
            'headers' => [
                ['name' => 'X-Frame-Options', 'value' => 'DENY'],
                ['name' => 'Content-Type', 'value' => 'should-be-ignored'],
            ],
        ],
    ]);

    Http::fake([
        'faas-nyc1.doserverless.co/*' => Http::response('hello', 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $slug = $site->meta['serverless']['proxy_slug'];
    $response = $this->get('/fn/'.$slug);

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');
    $response->assertHeader('X-Frame-Options', 'DENY');
});

test('custom domain middleware short circuits marketing route', function () {
    [, , $site] = functionSite([
        'routing' => [
            'custom_domains' => [
                ['hostname' => 'api.acme.com', 'mode' => 'auto', 'dns_status' => 'ready'],
            ],
        ],
    ]);

    Cache::flush();
    // force the host map to rebuild
    ResolveServerlessCustomDomain::invalidateHostMap();

    Http::fake([
        'faas-nyc1.doserverless.co/*' => Http::response('from-function', 200, []),
    ]);

    $response = $this->withServerVariables([
        'HTTP_HOST' => 'api.acme.com',
        'SERVER_NAME' => 'api.acme.com',
    ])->get('http://api.acme.com/');

    $response->assertOk();
    expect($response->getContent())->toBe('from-function');
});