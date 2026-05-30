<?php

namespace Tests\Unit\Services\RoutingConfigBuilderParityTest;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use App\Models\SiteRedirect;
use App\Models\SiteTenantDomain;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\TraefikSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('apache builder uses all webserver hostnames and redirects', function () {
    $site = routingSite();

    $config = app(ApacheSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('ServerName example.test', $config);
    $this->assertStringContainsString('ServerAlias www.example.test tenant.example.test', $config);
    $this->assertStringContainsString('RewriteEngine On', $config);
    $this->assertStringContainsString('RewriteRule ^/old$ https://example.test/new [R=301,L]', $config);
});

test('caddy builder uses all webserver hostnames and redirects', function () {
    $site = routingSite();

    $config = app(CaddySiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('example.test, www.example.test, tenant.example.test {', $config);
    $this->assertStringContainsString('redir /old https://example.test/new 301', $config);
});

test('caddy backend builder keeps redirects for traefik backends', function () {
    $site = routingSite();

    $config = app(CaddySiteConfigBuilder::class)->build($site, 23001);

    $this->assertStringContainsString('example.test:23001, www.example.test:23001, tenant.example.test:23001 {', $config);
    $this->assertStringContainsString('redir /old https://example.test/new 301', $config);
});

test('caddy switch-staging port binds hostnames not a catch-all port', function () {
    $siteA = Site::factory()->create(['slug' => 'alpha', 'type' => SiteType::Static]);
    SiteDomain::query()->create([
        'site_id' => $siteA->id,
        'hostname' => 'alpha.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $siteB = Site::factory()->create(['slug' => 'beta', 'type' => SiteType::Static]);
    SiteDomain::query()->create([
        'site_id' => $siteB->id,
        'hostname' => 'beta.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    $configA = app(CaddySiteConfigBuilder::class)->build($siteA->fresh(['domains']), 8080);
    $configB = app(CaddySiteConfigBuilder::class)->build($siteB->fresh(['domains']), 8080);

    expect($configA)->toMatch('/^alpha\.example\.test:8080 \{/m')
        ->and($configB)->toMatch('/^beta\.example\.test:8080 \{/m');
    $this->assertDoesNotMatchRegularExpression('/^:8080\s*\{/m', $configA);
    $this->assertDoesNotMatchRegularExpression('/^:8080\s*\{/m', $configB);
});

test('openlitespeed builder uses all webserver hostnames and redirects', function () {
    $site = routingSite();

    $config = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('vhDomain                  example.test,www.example.test,tenant.example.test', $config);
    $this->assertStringContainsString('RewriteRule ^old$ https://example.test/new [R=301,L]', $config);
});

test('openlitespeed php vhost references server-level lsapi handler', function () {
    $site = routingSite();
    $site->update(['type' => SiteType::Php]);
    $site = $site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects', 'server']);

    $config = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('add                     lsapi:lsphp83 php', $config);
    $this->assertStringNotContainsString('extprocessor', $config);
    $this->assertStringNotContainsString('extUser', $config);
});

test('traefik builder uses all webserver hostnames', function () {
    $site = routingSite();

    $config = app(TraefikSiteConfigBuilder::class)->build($site, 23001);

    $this->assertStringContainsString('Host(`example.test`) || Host(`www.example.test`) || Host(`tenant.example.test`)', $config);
});

test('apache builder proxies to octane port for php', function () {
    $site = routingSite();
    $site->update([
        'type' => SiteType::Php,
        'octane_port' => 8010,
    ]);

    $config = app(ApacheSiteConfigBuilder::class)->build($site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']));

    $this->assertStringContainsString('(Laravel Octane)', $config);
    $this->assertStringContainsString('ProxyPass / http://127.0.0.1:8010/', $config);
});

test('caddy builder proxies to octane port for php', function () {
    $site = routingSite();
    $site->update([
        'type' => SiteType::Php,
        'octane_port' => 8011,
    ]);

    $config = app(CaddySiteConfigBuilder::class)->build($site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']));

    $this->assertStringContainsString('reverse_proxy 127.0.0.1:8011', $config);
});

test('suspended nginx apache caddy ols use suspended static root without proxy', function () {
    $site = routingSite();
    $site->update([
        'suspended_at' => now(),
        'type' => SiteType::Php,
        'octane_port' => 8010,
        'app_port' => 4000,
    ]);
    $site = $site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']);
    $suspendedRoot = $site->suspendedStaticRoot();

    $nginx = app(NginxSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString($suspendedRoot, $nginx);
    $this->assertStringContainsString('(suspended)', $nginx);
    $this->assertStringNotContainsString('proxy_pass', $nginx);
    $this->assertStringNotContainsString('fastcgi_pass', $nginx);

    $apache = app(ApacheSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString($suspendedRoot, $apache);
    $this->assertStringContainsString('(suspended)', $apache);
    $this->assertStringNotContainsString('ProxyPass', $apache);

    $caddy = app(CaddySiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString($suspendedRoot, $caddy);
    $this->assertStringNotContainsString('reverse_proxy', $caddy);

    $ols = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString($suspendedRoot, $ols);
    $this->assertStringNotContainsString('extprocessor', $ols);
});

test('suspended caddy backend block for traefik uses file server', function () {
    $site = routingSite();
    $site->update([
        'suspended_at' => now(),
        'type' => SiteType::Node,
        'app_port' => 3000,
    ]);
    $site = $site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']);
    $suspendedRoot = $site->suspendedStaticRoot();

    $backend = app(CaddySiteConfigBuilder::class)->build($site, 23001);
    $this->assertStringContainsString('example.test:23001', $backend);
    $this->assertStringContainsString($suspendedRoot, $backend);
    $this->assertStringContainsString('file_server', $backend);
    $this->assertStringNotContainsString('reverse_proxy', $backend);
});

function routingSite(): Site
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
        'kind' => SiteRedirectKind::Http,
        'from_path' => '/old',
        'to_url' => 'https://example.test/new',
        'status_code' => 301,
        'sort_order' => 1,
    ]);

    return $site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']);
}

test('internal rewrite emitted in all managed builders', function () {
    $site = routingSite();
    SiteRedirect::query()->create([
        'site_id' => $site->id,
        'kind' => SiteRedirectKind::InternalRewrite,
        'from_path' => '/legacy',
        'to_url' => '/new',
        'status_code' => 301,
        'sort_order' => 2,
    ]);
    $site = $site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']);

    $nginx = app(NginxSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('rewrite ^/legacy$ /new last;', $nginx);

    $apache = app(ApacheSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('RewriteRule ^/legacy$ /new [L]', $apache);

    $caddy = app(CaddySiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('rewrite /legacy /new', $caddy);

    $ols = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('RewriteRule ^legacy$ /new [L]', $ols);
});

test('http redirect response headers emitted for nginx apache caddy and noted for ols', function () {
    $site = routingSite();
    SiteRedirect::query()->create([
        'site_id' => $site->id,
        'kind' => SiteRedirectKind::Http,
        'from_path' => '/promo',
        'to_url' => 'https://example.test/sale',
        'status_code' => 302,
        'response_headers' => [
            ['name' => 'X-Dply-Redirect', 'value' => '1'],
            ['name' => 'Cache-Control', 'value' => 'no-store'],
        ],
        'sort_order' => 2,
    ]);
    $site = $site->fresh(['domains', 'domainAliases', 'tenantDomains', 'redirects']);

    $nginx = app(NginxSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('location = /promo {', $nginx);
    $this->assertStringContainsString('add_header X-Dply-Redirect "1" always;', $nginx);
    $this->assertStringContainsString('add_header Cache-Control "no-store" always;', $nginx);
    $this->assertStringContainsString('return 302 "https://example.test/sale";', $nginx);

    $apache = app(ApacheSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('Header always set "X-Dply-Redirect" "1"', $apache);
    $this->assertStringContainsString('Header always set "Cache-Control" "no-store"', $apache);

    $caddy = app(CaddySiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('handle @dply_redir_0 {', $caddy);
    $this->assertStringContainsString('header X-Dply-Redirect "1"', $caddy);
    $this->assertStringContainsString('redir https://example.test/sale 302', $caddy);

    $ols = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);
    $this->assertStringContainsString('Dply: custom response headers on HTTP redirects are not applied by Open LiteSpeed', $ols);
    $this->assertStringContainsString('RewriteRule ^promo$ https://example.test/sale [R=302,L]', $ols);
});
