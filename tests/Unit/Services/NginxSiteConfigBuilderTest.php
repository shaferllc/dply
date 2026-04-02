<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomainAlias;
use App\Models\SiteDomain;
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
}
