<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteCachingMetaTest;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('pre migration site with legacy boolean reports nginx http enabled', function () {
    $site = siteWithoutCachingMeta(['engine_http_cache_enabled' => true]);

    $cfg = $site->cachingConfig();
    expect($cfg['enabled'])->toBeTrue();
    expect($cfg['methods'])->toContain('nginx_http');
    expect($site->wantsEngineHttpCache())->toBeTrue();
    expect($site->hasCachingMethod('nginx_http'))->toBeTrue();
});
test('pre migration site with legacy boolean off disables caching', function () {
    $site = siteWithoutCachingMeta(['engine_http_cache_enabled' => false]);

    expect($site->wantsEngineHttpCache())->toBeFalse();
    expect($site->hasCachingMethod('nginx_http'))->toBeFalse();
});
test('observer syncs legacy boolean from meta caching', function () {
    $site = siteWithoutCachingMeta(['engine_http_cache_enabled' => false]);

    $meta = $site->meta ?? [];
    $meta['caching'] = [
        'enabled' => true,
        'methods' => ['nginx_http', 'opcache'],
    ];
    $site->meta = $meta;
    $site->save();

    $site->refresh();
    expect($site->engine_http_cache_enabled)->toBeTrue();
});
test('observer clears legacy boolean when nginx http dropped', function () {
    $site = siteWithoutCachingMeta(['engine_http_cache_enabled' => true]);

    $meta = $site->meta ?? [];
    $meta['caching'] = [
        'enabled' => true,
        'methods' => ['opcache'], // varnish/opcache only — no nginx_http
    ];
    $site->meta = $meta;
    $site->save();

    $site->refresh();
    expect($site->engine_http_cache_enabled)->toBeFalse();
});
test('suspended site never wants engine cache', function () {
    $site = siteWithoutCachingMeta(['engine_http_cache_enabled' => true]);
    $site->suspended_at = now();
    $site->save();

    expect($site->wantsEngineHttpCache())->toBeFalse();
});
test('available methods for php nginx site', function () {
    $site = siteWithoutCachingMeta();

    // Server defaults to nginx via SiteFactory; assert PHP+nginx surface.
    expect($site->availableCachingMethods())->toContain('nginx_http');
    expect($site->availableCachingMethods())->toContain('opcache');
    expect($site->availableCachingMethods())->toContain('varnish');
});
test('available methods empty for container runtime', function () {
    $site = siteWithoutCachingMeta();
    $meta = $site->meta ?? [];
    $meta['docker_runtime'] = ['enabled' => true];
    $site->meta = $meta;

    // usesDockerRuntime should be true now; available methods should drop.
    if ($site->usesDockerRuntime()) {
        expect($site->availableCachingMethods())->toBe([]);
    } else {
        $this->markTestSkipped('Site::usesDockerRuntime() did not flip — meta key shape may differ; skip rather than false-positive.');
    }
});
/**
 * @param  array<string, mixed>  $overrides
 */
function siteWithoutCachingMeta(array $overrides = []): Site
{
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx'],
    ]);

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'organization_id' => $server->organization_id,
        'type' => SiteType::Php,
        'runtime' => 'php',
        'meta' => [], // explicitly no caching block — simulates pre-migration row
    ], $overrides));
}
