<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessAssetHostTest;

use App\Models\Site;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteWithSlug(string $slug): Site
{
    return Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => $slug]],
    ]);
}

test('the prefix is the hostname label, so one rule routes the whole fleet', function () {
    $site = siteWithSlug('orders-a1b2c3d4');

    $hostname = ServerlessAssetHost::hostname($site);
    $prefix = ServerlessAssetHost::prefix($site);

    expect($hostname)->toStartWith('orders-a1b2c3d4-assets.');
    expect($prefix)->toBe('serverless-assets/orders-a1b2c3d4');

    // The equality the Cloudflare rewrite depends on.
    expect(ServerlessAssetHost::labelFromHostname($hostname))
        ->toBe(str_replace('serverless-assets/', '', $prefix));
});

/**
 * The isolation guarantee. A lazy quantifier in the routing regex would
 * capture "foo" here and serve this tenant's hostname out of a DIFFERENT
 * tenant's prefix — so this asserts the greedy behaviour explicitly.
 */
test('a slug containing -assets still resolves to its own prefix', function () {
    $site = siteWithSlug('foo-assets-a1b2c3d4');

    $hostname = ServerlessAssetHost::hostname($site);

    expect($hostname)->toStartWith('foo-assets-a1b2c3d4-assets.');
    expect(ServerlessAssetHost::labelFromHostname($hostname))->toBe('foo-assets-a1b2c3d4');
    expect(ServerlessAssetHost::prefix($site))->toBe('serverless-assets/foo-assets-a1b2c3d4');
});

test('the host pattern does not match the function hostname itself', function () {
    $site = siteWithSlug('orders-a1b2c3d4');

    expect(ServerlessAssetHost::labelFromHostname((string) $site->serverlessFunctionHost()))->toBeNull();
});

test('the host pattern is anchored to a dply apex', function () {
    expect(ServerlessAssetHost::labelFromHostname('orders-a1b2c3d4-assets.attacker.example'))->toBeNull();
});

test('a long slug is shortened so the asset hostname stays a legal dns label', function () {
    $slug = str_repeat('a', 60).'-a1b2c3d4';
    $site = siteWithSlug($slug);

    $label = (string) ServerlessAssetHost::label($site);
    $hostLabel = $label.ServerlessAssetHost::HOST_SUFFIX;

    expect(strlen($hostLabel))->toBeLessThanOrEqual(63);
    // Shortening must stay deterministic, or a site's assets would move.
    expect(ServerlessAssetHost::label($site->fresh()))->toBe($label);
});

test('label is null before a slug is allocated, so collectors never mint one', function () {
    $site = Site::factory()->create(['meta' => []]);

    expect(ServerlessAssetHost::label($site))->toBeNull();
    expect(ServerlessAssetHost::prefix($site))->toBeNull();
    expect($site->fresh()->serverlessConfig())->not->toHaveKey('proxy_slug');
});

test('hostnames include attached custom domains', function () {
    $site = Site::factory()->create([
        'meta' => ['serverless' => [
            'proxy_slug' => 'orders-a1b2c3d4',
            'assets' => ['custom_hostnames' => ['cdn.acme.com', 'CDN.ACME.COM']],
        ]],
    ]);

    expect(ServerlessAssetHost::customHostnames($site))->toBe(['cdn.acme.com']);
    expect(ServerlessAssetHost::hostnames($site))->toHaveCount(2);
});
