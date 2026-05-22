<?php

namespace Tests\Feature\Livewire\Sites;

use App\Http\Middleware\ResolveServerlessCustomDomain;
use App\Livewire\Sites\ServerlessRouting;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Serverless\ServerlessRoutingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ServerlessRoutingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $serverlessMeta
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function functionSite(array $serverlessMeta = []): array
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

    public function test_routing_page_renders_with_default_hostname_tab(): void
    {
        [$user, $server, $site] = $this->functionSite();

        Livewire::actingAs($user)
            ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
            ->assertOk()
            ->assertSee('Hostname & DNS')
            ->assertSee('Custom domains')
            ->assertSee('Redirects')
            ->assertSee('Headers & CORS')
            ->assertSee('Invocation URLs')
            ->assertSet('tab', 'hostname');
    }

    public function test_add_redirect_persists_to_site_meta(): void
    {
        [$user, $server, $site] = $this->functionSite();

        Livewire::actingAs($user)
            ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
            ->set('newRedirectFrom', '/old-path')
            ->set('newRedirectTo', 'https://new.example.com/landing')
            ->set('newRedirectStatus', 301)
            ->call('addRedirect');

        $site->refresh();
        $redirects = $site->meta['serverless']['routing']['redirects'] ?? [];
        $this->assertCount(1, $redirects);
        $this->assertSame('/old-path', $redirects[0]['from']);
        $this->assertSame('https://new.example.com/landing', $redirects[0]['to']);
        $this->assertSame(301, $redirects[0]['status']);
    }

    public function test_remove_redirect_drops_the_indexed_entry(): void
    {
        [$user, $server, $site] = $this->functionSite([
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
        $this->assertCount(1, $redirects);
        $this->assertSame('/b', $redirects[0]['from']);
    }

    public function test_add_header_persists_and_rejects_reserved_names(): void
    {
        [$user, $server, $site] = $this->functionSite();

        $component = Livewire::actingAs($user)
            ->test(ServerlessRouting::class, ['server' => $server, 'site' => $site])
            ->set('newHeaderName', 'Content-Type')
            ->set('newHeaderValue', 'text/html')
            ->call('addHeader');

        $site->refresh();
        $this->assertEmpty($site->meta['serverless']['routing']['headers'] ?? []);

        $component
            ->set('newHeaderName', 'X-Frame-Options')
            ->set('newHeaderValue', 'DENY')
            ->call('addHeader');

        $site->refresh();
        $headers = $site->meta['serverless']['routing']['headers'];
        $this->assertCount(1, $headers);
        $this->assertSame('X-Frame-Options', $headers[0]['name']);
        $this->assertSame('DENY', $headers[0]['value']);
    }

    public function test_save_cors_persists_normalised_csv_inputs(): void
    {
        [$user, $server, $site] = $this->functionSite();

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
        $this->assertTrue($cors['enabled']);
        $this->assertSame(['https://app.acme.com', 'https://staging.acme.com'], $cors['origins']);
        $this->assertSame(['GET', 'POST'], $cors['methods']);
        $this->assertSame(['Content-Type'], $cors['headers']);
        $this->assertTrue($cors['allow_credentials']);
        $this->assertSame(600, $cors['max_age']);
    }

    public function test_resolver_normalises_meta_state(): void
    {
        [, , $site] = $this->functionSite([
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

        $this->assertSame('/old', $routing['redirects'][0]['from']);
        $this->assertSame('X-Frame-Options', $routing['headers'][0]['name']);
        $this->assertTrue($routing['cors']['enabled']);
        $this->assertSame('api.acme.com', $routing['custom_domains'][0]['hostname']);
        $this->assertSame('ready', $routing['custom_domains'][0]['dns_status']);
    }

    public function test_proxy_controller_applies_redirect_rule_before_forwarding(): void
    {
        [, , $site] = $this->functionSite([
            'routing' => [
                'redirects' => [
                    ['from' => '/old', 'to' => 'https://new.example.com/here', 'status' => 308, 'kind' => 'exact'],
                ],
            ],
        ]);

        Http::fake(); // assert no upstream call happens

        $response = $this->get('/fn/'.$site->meta['serverless']['proxy_slug'].'/old');

        $response->assertStatus(308);
        $response->assertRedirect('https://new.example.com/here');
        Http::assertNothingSent();
    }

    public function test_proxy_controller_emits_cors_preflight_204(): void
    {
        [, , $site] = $this->functionSite([
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
    }

    public function test_proxy_controller_decorates_response_with_configured_headers_and_skips_reserved(): void
    {
        [, , $site] = $this->functionSite([
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
    }

    public function test_custom_domain_middleware_short_circuits_marketing_route(): void
    {
        [, , $site] = $this->functionSite([
            'routing' => [
                'custom_domains' => [
                    ['hostname' => 'api.acme.com', 'mode' => 'auto', 'dns_status' => 'ready'],
                ],
            ],
        ]);

        Cache::flush(); // force the host map to rebuild
        ResolveServerlessCustomDomain::invalidateHostMap();

        Http::fake([
            'faas-nyc1.doserverless.co/*' => Http::response('from-function', 200, []),
        ]);

        $response = $this->withServerVariables([
            'HTTP_HOST' => 'api.acme.com',
            'SERVER_NAME' => 'api.acme.com',
        ])->get('http://api.acme.com/');

        $response->assertOk();
        $this->assertSame('from-function', $response->getContent());
    }
}
