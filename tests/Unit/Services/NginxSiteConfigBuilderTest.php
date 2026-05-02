<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Services\Sites\NginxSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_basic_auth_adds_acme_bypass_and_htpasswd_for_static_site(): void
    {
        $site = Site::factory()->create([
            'slug' => 'auth-static',
            'type' => SiteType::Static,
            'document_root' => '/var/www/auth-static/public',
            'repository_path' => '/var/www/auth-static',
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'static.example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);
        SiteBasicAuthUser::factory()->create([
            'site_id' => $site->id,
            'username' => 'preview',
            'password_hash' => Hash::make('secret'),
            'path' => '/',
        ]);

        $site->refresh()->load('domains', 'redirects', 'basicAuthUsers');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('location ^~ /.well-known/acme-challenge/', $nginx);
        $this->assertStringContainsString('auth_basic_user_file '.$site->basicAuthHtpasswdPathForNormalizedPath('/').';', $nginx);
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

    public function test_engine_http_cache_adds_fastcgi_cache_directives_for_php_fpm(): void
    {
        $site = Site::factory()->create([
            'type' => SiteType::Php,
            'engine_http_cache_enabled' => true,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'cache-php.example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->refresh()->load('domains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $zone = config('sites.nginx_engine_fcgi_cache_zone');
        $this->assertStringContainsString('fastcgi_cache '.$zone.';', $nginx);
        $this->assertStringContainsString('X-Dply-Engine-Cache', $nginx);
    }

    public function test_engine_http_cache_omitted_when_site_suspended(): void
    {
        $site = Site::factory()->create([
            'type' => SiteType::Php,
            'engine_http_cache_enabled' => true,
            'suspended_at' => now(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'suspended.example.test',
            'is_primary' => true,
            'www_redirect' => false,
        ]);

        $site->refresh()->load('domains', 'redirects');
        $nginx = app(NginxSiteConfigBuilder::class)->build($site);

        $this->assertStringNotContainsString('fastcgi_cache', $nginx);
    }
}
