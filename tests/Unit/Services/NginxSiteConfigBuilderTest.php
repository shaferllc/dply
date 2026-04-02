<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Services\Sites\NginxSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NginxSiteConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_site_access_and_error_log_paths_are_included(): void
    {
        $site = Site::factory()->create([
            'slug' => 'my-app',
            'type' => SiteType::Static,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->refresh()->load('domains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $basename = $site->nginxConfigBasename();
        $this->assertStringContainsString('access_log /var/log/nginx/'.$basename.'-access.log;', $nginx);
        $this->assertStringContainsString('error_log /var/log/nginx/'.$basename.'-error.log;', $nginx);
    }

    public function test_webserver_hostnames_include_aliases_and_tenants_but_not_preview_domains(): void
    {
        $site = Site::factory()->create([
            'slug' => 'my-app',
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
        SitePreviewDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview.dply.cc',
            'is_primary' => true,
            'dns_status' => 'ready',
        ]);

        $site->refresh()->load('domains', 'domainAliases', 'tenantDomains', 'previewDomains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('server_name example.test www.example.test tenant.example.test;', $nginx);
        $this->assertStringNotContainsString('preview.dply.cc', $nginx);
    }

    public function test_octane_block_proxies_with_upgrade_headers(): void
    {
        $site = Site::factory()->create([
            'slug' => 'octane-app',
            'type' => SiteType::Php,
            'octane_port' => 8088,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'octane.example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->refresh()->load('domains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('(Laravel Octane)', $nginx);
        $this->assertStringContainsString('location @octane', $nginx);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8088;', $nginx);
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $nginx);
        $this->assertStringContainsString('proxy_set_header Connection "upgrade";', $nginx);
    }

    public function test_reverb_proxy_location_is_emitted_when_reverb_port_saved_in_meta(): void
    {
        $site = Site::factory()->create([
            'slug' => 'reverb-app',
            'type' => SiteType::Php,
            'meta' => [
                'laravel_reverb' => [
                    'port' => 6000,
                    'ws_path' => '/app',
                ],
            ],
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'reverb.example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->refresh()->load('domains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('location ^~ /app', $nginx);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:6000;', $nginx);
    }

    public function test_octane_and_reverb_proxy_locations_coexist(): void
    {
        $site = Site::factory()->create([
            'slug' => 'octane-reverb',
            'type' => SiteType::Php,
            'octane_port' => 8088,
            'meta' => [
                'laravel_reverb' => ['port' => 6001, 'ws_path' => '/app'],
            ],
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'both.example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->refresh()->load('domains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('location @octane', $nginx);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:8088;', $nginx);
        $this->assertStringContainsString('location ^~ /app', $nginx);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:6001;', $nginx);
    }
}
