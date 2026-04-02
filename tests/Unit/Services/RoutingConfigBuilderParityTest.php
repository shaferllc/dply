<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SiteRedirect;
use App\Models\SiteTenantDomain;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\TraefikSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingConfigBuilderParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_apache_builder_uses_all_webserver_hostnames_and_redirects(): void
    {
        $site = $this->routingSite();

        $config = app(ApacheSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('ServerName example.test', $config);
        $this->assertStringContainsString('ServerAlias www.example.test tenant.example.test', $config);
        $this->assertStringContainsString('Redirect 301 /old https://example.test/new', $config);
    }

    public function test_caddy_builder_uses_all_webserver_hostnames_and_redirects(): void
    {
        $site = $this->routingSite();

        $config = app(CaddySiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('example.test, www.example.test, tenant.example.test {', $config);
        $this->assertStringContainsString('redir /old https://example.test/new 301', $config);
    }

    public function test_caddy_backend_builder_keeps_redirects_for_traefik_backends(): void
    {
        $site = $this->routingSite();

        $config = app(CaddySiteConfigBuilder::class)->build($site, 23001);

        $this->assertStringContainsString(':23001 {', $config);
        $this->assertStringContainsString('redir /old https://example.test/new 301', $config);
    }

    public function test_openlitespeed_builder_uses_all_webserver_hostnames_and_redirects(): void
    {
        $site = $this->routingSite();

        $config = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('vhDomain                  example.test,www.example.test,tenant.example.test', $config);
        $this->assertStringContainsString('RewriteRule ^old$ https://example.test/new [R=301,L]', $config);
    }

    public function test_traefik_builder_uses_all_webserver_hostnames(): void
    {
        $site = $this->routingSite();

        $config = app(TraefikSiteConfigBuilder::class)->build($site, 23001);

        $this->assertStringContainsString('Host(`example.test`) || Host(`www.example.test`) || Host(`tenant.example.test`)', $config);
    }

    private function routingSite(): Site
    {
        $site = Site::factory()->create([
            'slug' => 'routing-app',
            'type' => SiteType::Static,
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        SiteDomainAlias::query()->create([
            'site_id' => $site->id,
            'hostname' => 'www.example.test',
            'label' => 'www',
        ]);

        SiteTenantDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'tenant.example.test',
            'tenant_key' => 'tenant-1',
        ]);

        SiteRedirect::query()->create([
            'site_id' => $site->id,
            'from_path' => '/old',
            'to_url' => 'https://example.test/new',
            'status_code' => 301,
            'sort_order' => 1,
        ]);

        return $site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']);
    }
}
