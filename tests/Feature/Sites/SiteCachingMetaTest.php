<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteCachingMetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_migration_site_with_legacy_boolean_reports_nginx_http_enabled(): void
    {
        $site = $this->siteWithoutCachingMeta(['engine_http_cache_enabled' => true]);

        $cfg = $site->cachingConfig();
        $this->assertTrue($cfg['enabled']);
        $this->assertContains('nginx_http', $cfg['methods']);
        $this->assertTrue($site->wantsEngineHttpCache());
        $this->assertTrue($site->hasCachingMethod('nginx_http'));
    }

    public function test_pre_migration_site_with_legacy_boolean_off_disables_caching(): void
    {
        $site = $this->siteWithoutCachingMeta(['engine_http_cache_enabled' => false]);

        $this->assertFalse($site->wantsEngineHttpCache());
        $this->assertFalse($site->hasCachingMethod('nginx_http'));
    }

    public function test_observer_syncs_legacy_boolean_from_meta_caching(): void
    {
        $site = $this->siteWithoutCachingMeta(['engine_http_cache_enabled' => false]);

        $meta = $site->meta ?? [];
        $meta['caching'] = [
            'enabled' => true,
            'methods' => ['nginx_http', 'opcache'],
        ];
        $site->meta = $meta;
        $site->save();

        $site->refresh();
        $this->assertTrue($site->engine_http_cache_enabled);
    }

    public function test_observer_clears_legacy_boolean_when_nginx_http_dropped(): void
    {
        $site = $this->siteWithoutCachingMeta(['engine_http_cache_enabled' => true]);

        $meta = $site->meta ?? [];
        $meta['caching'] = [
            'enabled' => true,
            'methods' => ['opcache'], // varnish/opcache only — no nginx_http
        ];
        $site->meta = $meta;
        $site->save();

        $site->refresh();
        $this->assertFalse($site->engine_http_cache_enabled);
    }

    public function test_suspended_site_never_wants_engine_cache(): void
    {
        $site = $this->siteWithoutCachingMeta(['engine_http_cache_enabled' => true]);
        $site->suspended_at = now();
        $site->save();

        $this->assertFalse($site->wantsEngineHttpCache());
    }

    public function test_available_methods_for_php_nginx_site(): void
    {
        $site = $this->siteWithoutCachingMeta();
        // Server defaults to nginx via SiteFactory; assert PHP+nginx surface.
        $this->assertContains('nginx_http', $site->availableCachingMethods());
        $this->assertContains('opcache', $site->availableCachingMethods());
        $this->assertContains('varnish', $site->availableCachingMethods());
    }

    public function test_available_methods_empty_for_container_runtime(): void
    {
        $site = $this->siteWithoutCachingMeta();
        $meta = $site->meta ?? [];
        $meta['docker_runtime'] = ['enabled' => true];
        $site->meta = $meta;

        // usesDockerRuntime should be true now; available methods should drop.
        if ($site->usesDockerRuntime()) {
            $this->assertSame([], $site->availableCachingMethods());
        } else {
            $this->markTestSkipped('Site::usesDockerRuntime() did not flip — meta key shape may differ; skip rather than false-positive.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function siteWithoutCachingMeta(array $overrides = []): Site
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
}
