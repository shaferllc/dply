<?php

namespace Tests\Feature\ServerlessFunctionProxyTest;

use App\Models\Site;
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

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://faas.example/api/v1/web/ns/default/orders'));
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

    $this->get('http://orders-api.dply.cc/')
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

    $this->get('/fn/pending-fn')->assertStatus(503);
});

test('proxy slugs are unique', function () {
    $a = Site::factory()->create(['name' => 'Laravel demo']);
    $b = Site::factory()->create(['name' => 'Laravel demo']);

    $slugA = $a->ensureServerlessProxySlug();
    $slugB = $b->ensureServerlessProxySlug();

    expect($slugA)->toBe('laravel-demo');
    $this->assertNotSame($slugA, $slugB);
    expect($slugB)->toStartWith('laravel-demo-');
});
