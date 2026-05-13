<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Sites\SiteCacheDirectivesBuilder;
use Tests\TestCase;

class SiteCacheDirectivesBuilderTest extends TestCase
{
    public function test_nginx_fastcgi_emits_nothing_when_method_not_enabled(): void
    {
        $site = $this->siteWithCaching([
            'enabled' => false,
            'methods' => [],
        ]);

        $this->assertSame('', app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site));
    }

    public function test_nginx_fastcgi_emits_zone_when_nginx_http_method_active(): void
    {
        $site = $this->siteWithCaching([
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
    }

    public function test_nginx_invalid_ttl_falls_back_to_default(): void
    {
        $site = $this->siteWithCaching([
            'enabled' => true,
            'methods' => ['nginx_http'],
            'nginx_http' => [
                'fcgi' => ['ttl_200' => 'not-a-ttl', 'ttl_404' => '10m', 'min_uses' => 1],
                'proxy' => ['ttl_200' => '60m', 'ttl_404' => '10m'],
            ],
        ]);

        $out = app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site);

        $this->assertStringContainsString('fastcgi_cache_valid 200 60m;', $out);
    }

    public function test_bypass_cookies_append_to_bypass_vars(): void
    {
        $site = $this->siteWithCaching([
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
    }

    public function test_wildcard_cookies_dropped(): void
    {
        $site = $this->siteWithCaching([
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
    }

    public function test_ols_lscache_block_empty_when_disabled(): void
    {
        $site = $this->siteWithCaching([
            'enabled' => true,
            'methods' => [],
            'lscache' => ['enabled' => false, 'rules' => []],
        ]);

        $this->assertSame('', app(SiteCacheDirectivesBuilder::class)->olsLscacheBlock($site));
    }

    public function test_ols_lscache_block_renders_when_enabled(): void
    {
        $site = $this->siteWithCaching([
            'enabled' => true,
            'methods' => ['lscache'],
            'lscache' => ['enabled' => true, 'ttl' => 300, 'rules' => []],
        ]);

        $out = app(SiteCacheDirectivesBuilder::class)->olsLscacheBlock($site);

        $this->assertStringContainsString('enableCache             1', $out);
        $this->assertStringContainsString('expireInSeconds         300', $out);
    }

    /**
     * Build an unsaved Site instance with the given caching meta block.
     * No DB hit — the builder reads `$site->cachingConfig()` which just
     * reads `meta` and the methods accessor.
     *
     * @param  array<string, mixed>  $caching
     */
    private function siteWithCaching(array $caching): Site
    {
        $site = new Site;
        $site->type = SiteType::Php;
        $site->meta = ['caching' => $caching];
        $site->engine_http_cache_enabled = false;
        $site->suspended_at = null;

        return $site;
    }
}
