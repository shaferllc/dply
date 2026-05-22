<?php


namespace Tests\Unit\Services\NginxSiteConfigBuilderTest;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Services\Sites\NginxSiteConfigBuilder;
use Illuminate\Support\Facades\Hash;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('node block routes to internal port when set', function () {
    $site = Site::factory()->create([
        'slug' => 'jobs-app',
        'type' => SiteType::Node,
        'app_port' => 3000,
        'internal_port' => 30007,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'jobs.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $site->refresh()->load('domains', 'redirects');
    $nginx = app(NginxSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('proxy_pass http://127.0.0.1:30007;', $nginx);
    $this->assertStringNotContainsString('proxy_pass http://127.0.0.1:3000;', $nginx);
});

test('node block falls back to app port when internal port is null', function () {
    // Sites created before the runtime-agnostic columns landed only
    // have app_port. Routing must still work for them.
    $site = Site::factory()->create([
        'slug' => 'legacy-node',
        'type' => SiteType::Node,
        'app_port' => 4001,
        'internal_port' => null,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'legacy.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $site->refresh()->load('domains', 'redirects');
    $nginx = app(NginxSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('proxy_pass http://127.0.0.1:4001;', $nginx);
});

test('per site access and error log paths are included', function () {
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
});

test('webserver hostnames include aliases tenants and preview domains', function () {
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

    // Preview hostnames must land in server_name so the temporary
    // testing URL (issued by TestingHostnameProvisioner) actually
    // routes to this site's nginx block instead of falling through
    // to the default server and serving a bare 404.
    $this->assertStringContainsString('server_name example.test www.example.test tenant.example.test preview.dply.cc;', $nginx);
});

test('basic auth adds acme bypass and htpasswd for static site', function () {
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
});

test('octane block proxies with upgrade headers', function () {
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
});

test('reverb proxy location is emitted when reverb port saved in meta', function () {
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
});

test('octane and reverb proxy locations coexist', function () {
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
});

test('engine http cache adds fastcgi cache directives for php fpm', function () {
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
});

test('engine http cache omitted when site suspended', function () {
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
});

test('listen port mode rewrites listens and strips tls', function () {
    $site = Site::factory()->create([
        'slug' => 'switch-target',
        'type' => SiteType::Static,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $site->refresh()->load('domains', 'redirects');

    $production = app(NginxSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('listen 80;', $production);

    $testPort = app(NginxSiteConfigBuilder::class)->build($site, null, 8080);
    $this->assertStringContainsString('listen 8080;', $testPort);
    $this->assertStringNotContainsString('listen 80;', $testPort);
    $this->assertStringNotContainsString('listen [::]:80;', $testPort);

    // No TLS plumbing should remain at the test port.
    $this->assertStringNotContainsString('listen 443', $testPort);
    $this->assertStringNotContainsString('ssl_certificate', $testPort);
});