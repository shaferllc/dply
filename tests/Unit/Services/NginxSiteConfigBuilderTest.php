<?php

namespace Tests\Unit\Services\NginxSiteConfigBuilderTest;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteCertificate;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteTenantDomain;
use App\Services\Sites\NginxSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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

test('static nginx config includes managed 500 error page directives when the branded page is enabled', function () {
    // Branded interception is opt-in per site; the platform default lets the app
    // render its own errors (see the "does not intercept by default" test below).
    $site = Site::factory()->create([
        'slug' => 'error-pages',
        'type' => SiteType::Static,
        'meta' => ['expose_server_errors' => false],
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'errors.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $site->refresh()->load('domains', 'redirects');
    $nginx = app(NginxSiteConfigBuilder::class)->build($site);

    expect($nginx)
        ->toContain('error_page 500 502 503 504 /__dply__/errors/500.html;')
        ->toContain($site->managedErrorPagesRoot().'/500.html');
});

test('nginx config does not intercept 5xx by default — the app renders its own errors', function () {
    $site = Site::factory()->create([
        'slug' => 'app-errors',
        'type' => SiteType::Static,
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'app-errors.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $site->refresh()->load('domains', 'redirects');
    $nginx = app(NginxSiteConfigBuilder::class)->build($site);

    expect($nginx)
        ->not->toContain('error_page 500 502 503 504 /__dply__/errors/500.html;')
        ->not->toContain('/__dply__/errors/500.html');
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

test('form password gate emits auth_request and skips htpasswd directives', function () {
    $site = Site::factory()->create([
        'slug' => 'form-gate-static',
        'type' => SiteType::Static,
        'document_root' => '/var/www/form-gate-static/public',
        'repository_path' => '/var/www/form-gate-static',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'form-gate-static.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);
    $salt = bin2hex(random_bytes(16));
    SiteAccessGate::query()->create([
        'site_id' => $site->id,
        'method' => SiteAccessGate::METHOD_FORM_PASSWORD,
        'cookie_secret' => Str::random(48),
    ]);
    SiteAccessGatePassword::query()->create([
        'site_id' => $site->id,
        'label' => 'Preview',
        'password_salt' => $salt,
        'password_verifier' => hash('sha256', $salt.'secret'),
        'sort_order' => 0,
    ]);
    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => 'preview',
        'password_hash' => Hash::make('secret'),
        'path' => '/',
    ]);

    $site->refresh()->load('domains', 'redirects', 'basicAuthUsers', 'accessGate', 'accessGatePasswords');
    $nginx = app(NginxSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('auth_request /__dply/access/verify', $nginx);
    $this->assertStringContainsString('location = /__dply/access/verify', $nginx);
    $this->assertStringContainsString('include fastcgi.conf', $nginx);
    $this->assertStringNotContainsString('snippets/fastcgi-php.conf', $nginx);
    $this->assertStringNotContainsString('auth_basic_user_file', $nginx);
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

test('active certificate appends https server block with basic auth', function () {
    $site = Site::factory()->create([
        'slug' => 'auth-tls',
        'type' => SiteType::Php,
        'document_root' => '/var/www/auth-tls/public',
        'repository_path' => '/var/www/auth-tls',
        'ssl_status' => Site::SSL_ACTIVE,
    ]);
    SitePreviewDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'auth-tls.on-dply.com',
        'is_primary' => true,
    ]);
    SiteCertificate::query()->create([
        'site_id' => $site->id,
        'scope_type' => SiteCertificate::SCOPE_PREVIEW,
        'provider_type' => SiteCertificate::PROVIDER_LETSENCRYPT,
        'challenge_type' => SiteCertificate::CHALLENGE_HTTP,
        'status' => SiteCertificate::STATUS_ACTIVE,
        'last_installed_at' => now(),
        'domains_json' => ['auth-tls.on-dply.com'],
    ]);
    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => 'ops',
        'password_hash' => SiteBasicAuthUser::apr1Hash('secret'),
        'path' => '/',
    ]);

    $site->refresh()->load('domains', 'redirects', 'basicAuthUsers', 'previewDomains');
    $nginx = app(NginxSiteConfigBuilder::class)->build($site);

    expect(substr_count($nginx, 'listen 80;'))->toBe(1)
        ->and(substr_count($nginx, 'listen 443 ssl;'))->toBe(1)
        ->and($nginx)->toContain('ssl_certificate /etc/letsencrypt/live/auth-tls.on-dply.com/fullchain.pem;')
        ->and($nginx)->toContain('auth_basic_user_file '.$site->basicAuthHtpasswdPathForNormalizedPath('/').';');
});
