<?php

declare(strict_types=1);

namespace Tests\Unit\SiteCacheDirectivesBuilderTest;
use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Sites\SiteCacheDirectivesBuilder;
test('nginx fastcgi emits nothing when method not enabled', function () {
    $site = siteWithCaching([
        'enabled' => false,
        'methods' => [],
    ]);

    expect(app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site))->toBe('');
});
test('nginx fastcgi emits zone when nginx http method active', function () {
    $site = siteWithCaching([
        'enabled' => true,
        'methods' => ['nginx_http'],
        'nginx_http' => [
            'fcgi' => ['ttl_200' => '5m', 'ttl_404' => '30s', 'min_uses' => 2],
            'proxy' => ['ttl_200' => '60m', 'ttl_404' => '10m'],
            'bypass_cookies' => [],
        ],
    ]);

    $out = app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site);

    $this->assertStringContainsString('fastcgi_cache_valid 200 5m;', $out);
    $this->assertStringContainsString('fastcgi_cache_valid 404 30s;', $out);
    $this->assertStringContainsString('fastcgi_cache_min_uses 2;', $out);
    $this->assertStringContainsString('X-Dply-Engine-Cache', $out);
});
test('nginx invalid ttl falls back to default', function () {
    $site = siteWithCaching([
        'enabled' => true,
        'methods' => ['nginx_http'],
        'nginx_http' => [
            'fcgi' => ['ttl_200' => 'not-a-ttl', 'ttl_404' => '10m', 'min_uses' => 1],
            'proxy' => ['ttl_200' => '60m', 'ttl_404' => '10m'],
        ],
    ]);

    $out = app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site);

    $this->assertStringContainsString('fastcgi_cache_valid 200 60m;', $out);
});
test('bypass cookies append to bypass vars', function () {
    $site = siteWithCaching([
        'enabled' => true,
        'methods' => ['nginx_http'],
        'nginx_http' => [
            'fcgi' => ['ttl_200' => '60m', 'ttl_404' => '10m', 'min_uses' => 1],
            'proxy' => ['ttl_200' => '60m', 'ttl_404' => '10m'],
            'bypass_cookies' => ['phpsessid', 'laravel_session'],
        ],
    ]);

    $out = app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site);

    $this->assertStringContainsString('$cookie_phpsessid', $out);
    $this->assertStringContainsString('$cookie_laravel_session', $out);
});
test('wildcard cookies dropped', function () {
    $site = siteWithCaching([
        'enabled' => true,
        'methods' => ['nginx_http'],
        'nginx_http' => [
            'fcgi' => ['ttl_200' => '60m', 'ttl_404' => '10m', 'min_uses' => 1],
            'proxy' => ['ttl_200' => '60m', 'ttl_404' => '10m'],
            'bypass_cookies' => ['wordpress_logged_in_*', 'phpsessid'],
        ],
    ]);

    $out = app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site);

    $this->assertStringContainsString('$cookie_phpsessid', $out);
    $this->assertStringNotContainsString('wordpress_logged_in', $out);
});
test('ols lscache block empty when disabled', function () {
    $site = siteWithCaching([
        'enabled' => true,
        'methods' => [],
        'lscache' => ['enabled' => false, 'rules' => []],
    ]);

    expect(app(SiteCacheDirectivesBuilder::class)->olsLscacheBlock($site))->toBe('');
});
test('ols lscache block renders when enabled', function () {
    $site = siteWithCaching([
        'enabled' => true,
        'methods' => ['lscache'],
        'lscache' => ['enabled' => true, 'ttl' => 300, 'rules' => []],
    ]);

    $out = app(SiteCacheDirectivesBuilder::class)->olsLscacheBlock($site);

    $this->assertStringContainsString('enableCache             1', $out);
    $this->assertStringContainsString('expireInSeconds         300', $out);
});
/**
 * Build an unsaved Site instance with the given caching meta block.
 * No DB hit — the builder reads `$site->cachingConfig()` which just
 * reads `meta` and the methods accessor.
 *
 * @param  array<string, mixed>  $caching
 */
function siteWithCaching(array $caching): Site
{
    $site = new Site;
    $site->type = SiteType::Php;
    $site->meta = ['caching' => $caching];
    $site->engine_http_cache_enabled = false;
    $site->suspended_at = null;

    return $site;
}
