<?php

namespace Tests\Feature\ServerlessFunctionProxyTest;

use App\Models\Site;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('it proxies a friendly url to the function', function () {
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

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://faas.example/api/v1/web/ns/default/orders')
        && $request->hasHeader('X-Forwarded-Host')
        && $request->hasHeader('X-Forwarded-Proto'));
});

test('it proxies a testing domain subdomain', function () {
    Http::fake([
        'https://faas.example/*' => Http::response('reached via subdomain', 200, ['Content-Type' => 'text/plain']),
    ]);

    Site::factory()->create([
        'meta' => ['serverless' => [
            'proxy_slug' => 'orders-api',
            'action_url' => 'https://faas.example/api/v1/web/ns/default/orders',
        ]],
    ]);

    // Domain routes are registered at boot from DPLY_TESTING_DOMAINS
    // (.env.testing → dply.test). Using a domain outside that pool falls
    // through to the coming-soon guest redirect.
    $domain = (string) (config('services.digitalocean.testing_domains')[0] ?? 'dply.test');

    $this->get('http://orders-api.'.$domain.'/')
        ->assertOk()
        ->assertSee('reached via subdomain');
});

test('unknown slug is a 404', function () {
    $this->get('/fn/does-not-exist')->assertNotFound();
});

test('an undeployed function returns 503', function () {
    Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'pending-fn']],
    ]);

    // Lookout's debug page intercepts HttpExceptions when APP_DEBUG=true and
    // surfaces them as a 500 HTML page. Assert the real abort status with
    // debug off (production-shaped).
    config(['app.debug' => false]);

    $this->get('/fn/pending-fn')->assertStatus(503);
});

test('new proxy slugs include a stable site-id suffix so same-name sites do not collide', function () {
    $a = Site::factory()->create(['name' => 'Laravel demo', 'slug' => 'laravel-demo']);
    $b = Site::factory()->create(['name' => 'Laravel demo', 'slug' => 'laravel-demo']);

    $slugA = $a->ensureServerlessProxySlug();
    $slugB = $b->ensureServerlessProxySlug();

    expect($slugA)->toBe('laravel-demo-'.substr(sha1((string) $a->id), 0, 8));
    expect($slugB)->toBe('laravel-demo-'.substr(sha1((string) $b->id), 0, 8));
    $this->assertNotSame($slugA, $slugB);
    $this->assertNotSame($a->serverlessFunctionHost(), $b->serverlessFunctionHost());
});

test('an already allocated proxy slug is not reminted', function () {
    $site = Site::factory()->create([
        'name' => 'Placehold',
        'slug' => 'placehold',
        'meta' => ['serverless' => ['proxy_slug' => 'placehold']],
    ]);

    expect($site->ensureServerlessProxySlug())->toBe('placehold');
    expect($site->fresh()->ensureServerlessProxySlug())->toBe('placehold');
    expect($site->serverlessFriendlyUrl())->toBe(
        'https://placehold.'.ServerlessTestingDomains::apexFor($site->id)
    );
});

test('it proxies a minted unique testing hostname', function () {
    Http::fake([
        'https://faas.example/*' => Http::response('reached via unique host', 200, ['Content-Type' => 'text/plain']),
    ]);

    $site = Site::factory()->create([
        'name' => 'Orders API',
        'slug' => 'orders-api',
        'meta' => ['serverless' => [
            'action_url' => 'https://faas.example/api/v1/web/ns/default/orders',
        ]],
    ]);
    $slug = $site->ensureServerlessProxySlug();

    $domain = (string) (config('services.digitalocean.testing_domains')[0] ?? 'dply.test');

    $this->get('http://'.$slug.'.'.$domain.'/')
        ->assertOk()
        ->assertSee('reached via unique host');
});
