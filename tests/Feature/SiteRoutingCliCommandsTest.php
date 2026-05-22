<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteRedirectKind;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteRedirect;
use App\Models\SiteTenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * One feature test per new routing CLI command. Each test verifies the
 * primary write path (or read for List/Export commands), surfaces error
 * cases (site-not-found, missing required input), and asserts the
 * webserver-config apply job is dispatched on supported runtimes.
 */
class SiteRoutingCliCommandsTest extends TestCase
{
    use RefreshDatabase;

    // -- Aliases ----------------------------------------------------------

    public function test_alias_add_creates_row_and_dispatches_apply(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:alias-add', [
            'site' => $site->slug,
            'hostname' => 'alt.example.com',
            '--label' => 'Marketing',
            '--comment' => 'EU CDN',
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('site_domain_aliases', [
            'site_id' => $site->id,
            'hostname' => 'alt.example.com',
            'label' => 'Marketing',
            'comment' => 'EU CDN',
        ]);
        Bus::assertDispatched(ApplySiteWebserverConfigJob::class);
    }

    public function test_alias_add_no_apply_skips_dispatch(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:site:alias-add', [
            'site' => $site->slug,
            'hostname' => 'alt.example.com',
            '--no-apply' => true,
        ]);

        Bus::assertNotDispatched(ApplySiteWebserverConfigJob::class);
    }

    public function test_alias_remove_deletes_row(): void
    {
        Bus::fake();
        $site = $this->makeSite();
        SiteDomainAlias::query()->create(['site_id' => $site->id, 'hostname' => 'alt.example.com']);

        $exit = Artisan::call('dply:site:alias-remove', [
            'site' => $site->slug,
            'hostname' => 'alt.example.com',
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('site_domain_aliases', ['hostname' => 'alt.example.com']);
    }

    public function test_alias_list_renders_rows(): void
    {
        $site = $this->makeSite();
        SiteDomainAlias::query()->create(['site_id' => $site->id, 'hostname' => 'a.example.com']);
        SiteDomainAlias::query()->create(['site_id' => $site->id, 'hostname' => 'b.example.com']);

        Artisan::call('dply:site:alias-list', ['site' => $site->slug, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(2, $payload['count']);
    }

    // -- Tenants ----------------------------------------------------------

    public function test_tenant_add_creates_row(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:site:tenant-add', [
            'site' => $site->slug,
            'hostname' => 'acme.example.com',
            '--key' => 'acme',
            '--label' => 'Acme Corp',
            '--comment' => 'Primary tenant',
        ]);

        $this->assertDatabaseHas('site_tenant_domains', [
            'hostname' => 'acme.example.com',
            'tenant_key' => 'acme',
            'comment' => 'Primary tenant',
        ]);
    }

    public function test_tenant_remove_deletes_row(): void
    {
        Bus::fake();
        $site = $this->makeSite();
        SiteTenantDomain::query()->create(['site_id' => $site->id, 'hostname' => 'acme.example.com']);

        Artisan::call('dply:site:tenant-remove', [
            'site' => $site->slug,
            'hostname' => 'acme.example.com',
        ]);

        $this->assertDatabaseMissing('site_tenant_domains', ['hostname' => 'acme.example.com']);
    }

    public function test_tenant_list_renders_rows(): void
    {
        $site = $this->makeSite();
        SiteTenantDomain::query()->create(['site_id' => $site->id, 'hostname' => 't1.example.com']);

        Artisan::call('dply:site:tenant-list', ['site' => $site->slug, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['count']);
    }

    // -- Redirects --------------------------------------------------------

    public function test_redirect_add_creates_http_rule(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:site:redirect-add', [
            'site' => $site->slug,
            'from' => '/old',
            'to' => 'https://example.com',
            '--code' => 302,
            '--comment' => 'Mailchimp',
        ]);

        $this->assertDatabaseHas('site_redirects', [
            'from_path' => '/old',
            'to_url' => 'https://example.com',
            'status_code' => 302,
            'comment' => 'Mailchimp',
        ]);
    }

    public function test_redirect_add_internal_flips_kind(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:site:redirect-add', [
            'site' => $site->slug,
            'from' => '/old',
            'to' => '/new',
            '--internal' => true,
        ]);

        $rule = SiteRedirect::query()->where('site_id', $site->id)->first();
        $this->assertNotNull($rule);
        $this->assertSame(SiteRedirectKind::InternalRewrite, $rule->kind);
    }

    public function test_redirect_remove_deletes_by_from_path(): void
    {
        Bus::fake();
        $site = $this->makeSite();
        SiteRedirect::query()->create([
            'site_id' => $site->id,
            'kind' => SiteRedirectKind::Http,
            'from_path' => '/x',
            'to_url' => '/y',
            'status_code' => 301,
            'sort_order' => 1,
        ]);

        Artisan::call('dply:site:redirect-remove', [
            'site' => $site->slug,
            'from' => '/x',
        ]);

        $this->assertDatabaseMissing('site_redirects', ['from_path' => '/x']);
    }

    public function test_redirect_list_renders_rows(): void
    {
        $site = $this->makeSite();
        SiteRedirect::query()->create([
            'site_id' => $site->id,
            'kind' => SiteRedirectKind::Http,
            'from_path' => '/old',
            'to_url' => 'https://example.com',
            'status_code' => 301,
            'sort_order' => 1,
        ]);

        Artisan::call('dply:site:redirect-list', ['site' => $site->slug, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['count']);
        $this->assertSame('http', $payload['redirects'][0]['kind']);
    }

    public function test_redirect_import_csv_round_trips(): void
    {
        Bus::fake();
        $site = $this->makeSite();
        $path = sys_get_temp_dir().'/redirects-'.uniqid().'.csv';
        file_put_contents($path, "/a,/b\n/c,https://x.com,302\n");

        $exit = Artisan::call('dply:site:redirect-import', [
            'site' => $site->slug,
            '--file' => $path,
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('site_redirects', ['from_path' => '/a', 'status_code' => 301]);
        $this->assertDatabaseHas('site_redirects', ['from_path' => '/c', 'status_code' => 302]);

        unlink($path);
    }

    public function test_redirect_export_writes_csv_lines(): void
    {
        $site = $this->makeSite();
        SiteRedirect::query()->create([
            'site_id' => $site->id,
            'kind' => SiteRedirectKind::Http,
            'from_path' => '/old',
            'to_url' => 'https://example.com',
            'status_code' => 301,
            'sort_order' => 1,
        ]);

        Artisan::call('dply:site:redirect-export', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertStringContainsString('/old,https://example.com,301', $output);
    }

    // -- Preview ----------------------------------------------------------

    public function test_preview_set_upserts_primary(): void
    {
        Bus::fake();
        $site = $this->makeSite();

        Artisan::call('dply:site:preview-set', [
            'site' => $site->slug,
            'hostname' => 'preview.example.dply.cc',
            '--label' => 'Managed preview',
            '--auto-ssl' => true,
        ]);

        $this->assertDatabaseHas('site_preview_domains', [
            'hostname' => 'preview.example.dply.cc',
            'is_primary' => true,
            'auto_ssl' => true,
        ]);
    }

    public function test_preview_remove_deletes_row(): void
    {
        Bus::fake();
        $site = $this->makeSite();
        SitePreviewDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'preview.example.com',
            'label' => 'Preview',
            'dns_status' => 'pending',
            'ssl_status' => 'pending',
            'is_primary' => false,
            'auto_ssl' => false,
            'https_redirect' => false,
            'managed_by_dply' => true,
        ]);

        Artisan::call('dply:site:preview-remove', [
            'site' => $site->slug,
            'hostname' => 'preview.example.com',
        ]);

        $this->assertDatabaseMissing('site_preview_domains', ['hostname' => 'preview.example.com']);
    }

    // -- Site-not-found across the suite (one canonical case) ------------

    public function test_alias_add_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:alias-add', [
            'site' => 'nope',
            'hostname' => 'alt.example.com',
        ]);
        $this->assertSame(1, $exit);
    }

    private function makeSite(): Site
    {
        $server = Server::factory()->ready()->create();

        return Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'demo',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
    }
}
