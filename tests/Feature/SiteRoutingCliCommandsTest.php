<?php

declare(strict_types=1);

namespace Tests\Feature\SiteRoutingCliCommandsTest;
use App\Enums\SiteRedirectKind;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomainAlias;
use App\Models\SitePreviewDomain;
use App\Models\SiteRedirect;
use App\Models\SiteTenantDomain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('alias add creates row and dispatches apply', function () {
    Bus::fake();
    $site = makeSite();

    $exit = Artisan::call('dply:site:alias-add', [
        'site' => $site->slug,
        'hostname' => 'alt.example.com',
        '--label' => 'Marketing',
        '--comment' => 'EU CDN',
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('site_domain_aliases', [
        'site_id' => $site->id,
        'hostname' => 'alt.example.com',
        'label' => 'Marketing',
        'comment' => 'EU CDN',
    ]);
    Bus::assertDispatched(ApplySiteWebserverConfigJob::class);
});
test('alias add no apply skips dispatch', function () {
    Bus::fake();
    $site = makeSite();

    Artisan::call('dply:site:alias-add', [
        'site' => $site->slug,
        'hostname' => 'alt.example.com',
        '--no-apply' => true,
    ]);

    Bus::assertNotDispatched(ApplySiteWebserverConfigJob::class);
});
test('alias remove deletes row', function () {
    Bus::fake();
    $site = makeSite();
    SiteDomainAlias::query()->create(['site_id' => $site->id, 'hostname' => 'alt.example.com']);

    $exit = Artisan::call('dply:site:alias-remove', [
        'site' => $site->slug,
        'hostname' => 'alt.example.com',
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseMissing('site_domain_aliases', ['hostname' => 'alt.example.com']);
});
test('alias list renders rows', function () {
    $site = makeSite();
    SiteDomainAlias::query()->create(['site_id' => $site->id, 'hostname' => 'a.example.com']);
    SiteDomainAlias::query()->create(['site_id' => $site->id, 'hostname' => 'b.example.com']);

    Artisan::call('dply:site:alias-list', ['site' => $site->slug, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['count'])->toBe(2);
});
test('tenant add creates row', function () {
    Bus::fake();
    $site = makeSite();

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
});
test('tenant remove deletes row', function () {
    Bus::fake();
    $site = makeSite();
    SiteTenantDomain::query()->create(['site_id' => $site->id, 'hostname' => 'acme.example.com']);

    Artisan::call('dply:site:tenant-remove', [
        'site' => $site->slug,
        'hostname' => 'acme.example.com',
    ]);

    $this->assertDatabaseMissing('site_tenant_domains', ['hostname' => 'acme.example.com']);
});
test('tenant list renders rows', function () {
    $site = makeSite();
    SiteTenantDomain::query()->create(['site_id' => $site->id, 'hostname' => 't1.example.com']);

    Artisan::call('dply:site:tenant-list', ['site' => $site->slug, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['count'])->toBe(1);
});
test('redirect add creates http rule', function () {
    Bus::fake();
    $site = makeSite();

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
});
test('redirect add internal flips kind', function () {
    Bus::fake();
    $site = makeSite();

    Artisan::call('dply:site:redirect-add', [
        'site' => $site->slug,
        'from' => '/old',
        'to' => '/new',
        '--internal' => true,
    ]);

    $rule = SiteRedirect::query()->where('site_id', $site->id)->first();
    expect($rule)->not->toBeNull();
    expect($rule->kind)->toBe(SiteRedirectKind::InternalRewrite);
});
test('redirect remove deletes by from path', function () {
    Bus::fake();
    $site = makeSite();
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
});
test('redirect list renders rows', function () {
    $site = makeSite();
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

    expect($payload['count'])->toBe(1);
    expect($payload['redirects'][0]['kind'])->toBe('http');
});
test('redirect import csv round trips', function () {
    Bus::fake();
    $site = makeSite();
    $path = sys_get_temp_dir().'/redirects-'.uniqid().'.csv';
    file_put_contents($path, "/a,/b\n/c,https://x.com,302\n");

    $exit = Artisan::call('dply:site:redirect-import', [
        'site' => $site->slug,
        '--file' => $path,
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('site_redirects', ['from_path' => '/a', 'status_code' => 301]);
    $this->assertDatabaseHas('site_redirects', ['from_path' => '/c', 'status_code' => 302]);

    unlink($path);
});
test('redirect export writes csv lines', function () {
    $site = makeSite();
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
});
test('preview set upserts primary', function () {
    Bus::fake();
    $site = makeSite();

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
});
test('preview remove deletes row', function () {
    Bus::fake();
    $site = makeSite();
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
});
test('alias add fails when site not found', function () {
    $exit = Artisan::call('dply:site:alias-add', [
        'site' => 'nope',
        'hostname' => 'alt.example.com',
    ]);
    expect($exit)->toBe(1);
});
function makeSite(): Site
{
    $server = Server::factory()->ready()->create();

    return Site::factory()->create([
        'server_id' => $server->id,
        'slug' => 'demo',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
}
