<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerlessFunctionProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_proxies_a_friendly_url_to_the_function(): void
    {
        Http::fake([
            'https://faas.example/*' => Http::response('hello from the function', 200, ['Content-Type' => 'text/plain']),
        ]);

        Site::factory()->create([
            'meta' => ['serverless' => [
                'proxy_slug' => 'orders-api',
                'action_url' => 'https://faas.example/api/v1/web/ns/default/orders',
            ]],
        ]);

        $this->get('/fn/orders-api')
            ->assertOk()
            ->assertSee('hello from the function');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://faas.example/api/v1/web/ns/default/orders'));
    }

    public function test_it_proxies_a_testing_domain_subdomain(): void
    {
        Http::fake([
            'https://faas.example/*' => Http::response('reached via subdomain', 200, ['Content-Type' => 'text/plain']),
        ]);

        Site::factory()->create([
            'meta' => ['serverless' => [
                'proxy_slug' => 'orders-api',
                'action_url' => 'https://faas.example/api/v1/web/ns/default/orders',
            ]],
        ]);

        $this->get('http://orders-api.dply.cc/')
            ->assertOk()
            ->assertSee('reached via subdomain');
    }

    public function test_unknown_slug_is_a_404(): void
    {
        $this->get('/fn/does-not-exist')->assertNotFound();
    }

    public function test_an_undeployed_function_returns_503(): void
    {
        Site::factory()->create([
            'meta' => ['serverless' => ['proxy_slug' => 'pending-fn']],
        ]);

        $this->get('/fn/pending-fn')->assertStatus(503);
    }

    public function test_proxy_slugs_are_unique(): void
    {
        $a = Site::factory()->create(['name' => 'Laravel demo']);
        $b = Site::factory()->create(['name' => 'Laravel demo']);

        $slugA = $a->ensureServerlessProxySlug();
        $slugB = $b->ensureServerlessProxySlug();

        $this->assertSame('laravel-demo', $slugA);
        $this->assertNotSame($slugA, $slugB);
        $this->assertStringStartsWith('laravel-demo-', $slugB);
    }
}
