<?php

namespace Tests\Unit\Services\BasicAuthDirectivesAcrossEnginesTest;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteAccessGatePassword;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteDomain;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\TraefikSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function siteWithBasicAuth(string $username = 'preview', string $path = '/', ?string $hash = null): Site
{
    $site = Site::factory()->create([
        'slug' => 'auth-test',
        'type' => SiteType::Static,
        'document_root' => '/var/www/auth-test/public',
        'repository_path' => '/var/www/auth-test',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'auth.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);
    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => $username,
        'password_hash' => $hash ?? Hash::make('secret'),
        'path' => $path,
    ]);

    return $site->refresh()->load('domains', 'redirects', 'basicAuthUsers');
}

function siteWithFormPasswordGate(string $username = 'preview'): Site
{
    $site = Site::factory()->create([
        'slug' => 'form-gate-test',
        'type' => SiteType::Static,
        'document_root' => '/var/www/form-gate/public',
        'repository_path' => '/var/www/form-gate',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'form-gate.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);
    SiteAccessGate::query()->create([
        'site_id' => $site->id,
        'method' => SiteAccessGate::METHOD_FORM_PASSWORD,
        'cookie_secret' => Str::random(48),
    ]);
    SiteAccessGatePassword::factory()->create([
        'site_id' => $site->id,
        'label' => 'Preview',
    ]);
    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => $username,
        'password_hash' => Hash::make('secret'),
        'path' => '/',
    ]);

    return $site->refresh()->load('domains', 'redirects', 'basicAuthUsers', 'accessGate', 'accessGatePasswords');
}

test('apache emits authtype basic for root', function () {
    $site = siteWithBasicAuth();

    $apache = app(ApacheSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('AuthType Basic', $apache);
    $this->assertStringContainsString('AuthUserFile '.$site->basicAuthHtpasswdPathForNormalizedPath('/'), $apache);
    $this->assertStringContainsString('Require valid-user', $apache);
});

test('apache skips pending removal rows for directive emission', function () {
    $site = Site::factory()->create([
        'slug' => 'auth-apache-pending',
        'type' => SiteType::Static,
        'document_root' => '/var/www/x/public',
        'repository_path' => '/var/www/x',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'pending.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    // Only entry is pending removal — directives must NOT be emitted.
    SiteBasicAuthUser::factory()->create([
        'site_id' => $site->id,
        'username' => 'going-away',
        'password_hash' => Hash::make('x'),
        'path' => '/',
        'pending_removal_at' => now(),
    ]);

    $site->refresh()->load('domains', 'redirects', 'basicAuthUsers');
    $apache = app(ApacheSiteConfigBuilder::class)->build($site);

    $this->assertStringNotContainsString('AuthType Basic', $apache);
});

test('caddy emits basic auth block with bcrypt hash', function () {
    $site = siteWithBasicAuth();
    $hash = $site->basicAuthUsers->first()->password_hash;

    $caddy = app(CaddySiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('basic_auth {', $caddy);
    $this->assertStringContainsString('preview '.$hash, $caddy);
});

test('caddy skips non bcrypt hashes with comment', function () {
    // apr1 is the format Apache htpasswd writes by default; Caddy can't enforce it inline.
    $site = siteWithBasicAuth(hash: '$apr1$abcd$YYYYYYYYYYYYYYYYYYYYY');

    $caddy = app(CaddySiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('# dply: skipped non-bcrypt', $caddy);
    $this->assertStringNotContainsString('basic_auth {', $caddy);
});

test('traefik emits basicauth middleware with users file', function () {
    $site = Site::factory()->create([
        'slug' => 'auth-traefik',
        'type' => SiteType::Php,
        'app_port' => 9000,
        'document_root' => '/var/www/auth-traefik/public',
        'repository_path' => '/var/www/auth-traefik',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'traefik.example.test',
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
    $yaml = app(TraefikSiteConfigBuilder::class)->build($site, 9000);

    $this->assertStringContainsString('middlewares:', $yaml);
    $this->assertStringContainsString('basicAuth:', $yaml);
    $this->assertStringContainsString('usersFile: "'.$site->basicAuthHtpasswdPathForNormalizedPath('/').'"', $yaml);
    $this->assertStringContainsString('realm: "Restricted"', $yaml);
});

test('openlitespeed emits realm and root context auth', function () {
    $site = siteWithBasicAuth();

    $ols = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);

    $this->assertStringContainsString('realm dply_', $ols);
    $this->assertStringContainsString('location              '.$site->basicAuthHtpasswdPathForNormalizedPath('/'), $ols);
    $this->assertStringContainsString('authName                Restricted', $ols);
    $this->assertStringContainsString('required                valid-user', $ols);
});

test('form password gate skips basic auth directives across engines', function () {
    $site = siteWithFormPasswordGate();

    $apache = app(ApacheSiteConfigBuilder::class)->build($site);
    $this->assertStringNotContainsString('AuthType Basic', $apache);
    $this->assertStringContainsString('__dply_vm_access=', $apache);

    $caddy = app(CaddySiteConfigBuilder::class)->build($site);
    $this->assertStringNotContainsString('basic_auth {', $caddy);
    $this->assertStringContainsString('forward_auth /__dply/access/verify', $caddy);

    $ols = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);
    $this->assertStringNotContainsString('authName                Restricted', $ols);
});

test('traefik emits forward auth middleware for form password gate', function () {
    $site = Site::factory()->create([
        'slug' => 'auth-traefik-form',
        'type' => SiteType::Php,
        'app_port' => 9000,
        'document_root' => '/var/www/auth-traefik-form/public',
        'repository_path' => '/var/www/auth-traefik-form',
    ]);
    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'traefik-form.example.test',
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

    $site->refresh()->load('domains', 'redirects', 'accessGate', 'accessGatePasswords');
    $yaml = app(TraefikSiteConfigBuilder::class)->build($site, 9000);

    $this->assertStringContainsString('forwardAuth:', $yaml);
    $this->assertStringContainsString('/__dply/access/verify', $yaml);
    $this->assertStringNotContainsString('basicAuth:', $yaml);
});
