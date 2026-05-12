<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Models\SiteDomain;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use App\Services\Sites\TraefikSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BasicAuthDirectivesAcrossEnginesTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithBasicAuth(string $username = 'preview', string $path = '/', ?string $hash = null): Site
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

    public function test_apache_emits_authtype_basic_for_root(): void
    {
        $site = $this->siteWithBasicAuth();

        $apache = app(ApacheSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('AuthType Basic', $apache);
        $this->assertStringContainsString('AuthUserFile '.$site->basicAuthHtpasswdPathForNormalizedPath('/'), $apache);
        $this->assertStringContainsString('Require valid-user', $apache);
    }

    public function test_apache_skips_pending_removal_rows_for_directive_emission(): void
    {
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
    }

    public function test_caddy_emits_basic_auth_block_with_bcrypt_hash(): void
    {
        $site = $this->siteWithBasicAuth();
        $hash = $site->basicAuthUsers->first()->password_hash;

        $caddy = app(CaddySiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('basic_auth {', $caddy);
        $this->assertStringContainsString('preview '.$hash, $caddy);
    }

    public function test_caddy_skips_non_bcrypt_hashes_with_comment(): void
    {
        // apr1 is the format Apache htpasswd writes by default; Caddy can't enforce it inline.
        $site = $this->siteWithBasicAuth(hash: '$apr1$abcd$YYYYYYYYYYYYYYYYYYYYY');

        $caddy = app(CaddySiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('# dply: skipped non-bcrypt', $caddy);
        $this->assertStringNotContainsString('basic_auth {', $caddy);
    }

    public function test_traefik_emits_basicauth_middleware_with_users_file(): void
    {
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
    }

    public function test_openlitespeed_emits_realm_and_root_context_auth(): void
    {
        $site = $this->siteWithBasicAuth();

        $ols = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);

        $this->assertStringContainsString('realm dply_', $ols);
        $this->assertStringContainsString('location              '.$site->basicAuthHtpasswdPathForNormalizedPath('/'), $ols);
        $this->assertStringContainsString('authName                Restricted', $ols);
        $this->assertStringContainsString('required                valid-user', $ols);
    }
}
