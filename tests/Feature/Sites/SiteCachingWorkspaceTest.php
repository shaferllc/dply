<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteCachingWorkspaceTest;

use App\Jobs\ApplySiteWebserverConfigJob;
use App\Livewire\Sites\Caching;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Sites\SiteCacheDirectivesBuilder;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * GA coverage for the Caching workspace — the preview/coming-soon path is
 * covered separately in SiteCachingPreviewTest. These assert the surface an
 * operator actually gets once `workspace.site_caching` is on: the real panel
 * renders, toggles persist to `meta['caching']`, and the saved shape reaches
 * the vhost through SiteCacheDirectivesBuilder.
 */
beforeEach(function (): void {
    Feature::define('workspace.site_caching', fn (): bool => true);
    Feature::define('workspace.site_caching_preview', fn (): bool => false);
    Feature::flushCache();
});

test('caching tab has no soon badge once the feature is on', function (): void {
    [$user, $server, $site] = siteCachingWorkspaceFixtures();

    $caching = collect(SiteSettingsSidebar::items($site->fresh(), $server))
        ->firstWhere('id', 'caching');

    expect($caching)->not->toBeNull();
    expect($caching['preview'] ?? false)->toBeFalsy();
});

test('caching route renders the real panel not the teaser', function (): void {
    [$user, $server, $site] = siteCachingWorkspaceFixtures();

    // Assert on the teaser's own heading, not the words "Coming soon" — the
    // workspace sidebar still carries Soon badges for other previewed surfaces
    // (CDN / Edge), so that string is on the page either way.
    $this->actingAs($user)
        ->get(route('sites.caching', [$server, $site]))
        ->assertOk()
        ->assertSee(__('Enable caching for this site'))
        ->assertSee(__('Nginx HTTP cache'))
        ->assertDontSee(__('Site cache layers'));
});

test('toggling a method and saving persists the caching meta block', function (): void {
    Queue::fake();
    [$user, $server, $site] = siteCachingWorkspaceFixtures();

    Livewire::actingAs($user)
        ->test(Caching::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->call('toggleMethod', 'nginx_http')
        ->set('nginx_fcgi_ttl_200', '30m')
        ->set('bypass_cookies_input', 'laravel_session, phpsessid')
        ->call('save')
        ->assertHasNoErrors();

    $site->refresh();
    $cfg = $site->cachingConfig();

    expect($cfg['enabled'])->toBeTrue();
    expect($cfg['methods'])->toContain('nginx_http');
    expect($cfg['nginx_http']['fcgi']['ttl_200'])->toBe('30m');
    expect($cfg['nginx_http']['bypass_cookies'])->toBe(['laravel_session', 'phpsessid']);
    expect($site->hasCachingMethod('nginx_http'))->toBeTrue();

    // The vhost only picks the change up on re-apply, so the save must queue it.
    Queue::assertPushed(ApplySiteWebserverConfigJob::class);
});

test('saved settings reach the emitted nginx directives', function (): void {
    Queue::fake();
    [$user, $server, $site] = siteCachingWorkspaceFixtures();

    Livewire::actingAs($user)
        ->test(Caching::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->call('toggleMethod', 'nginx_http')
        ->set('nginx_fcgi_ttl_200', '30m')
        ->set('nginx_fcgi_min_uses', 3)
        ->set('bypass_cookies_input', 'laravel_session')
        ->call('save');

    $directives = app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site->fresh());

    expect($directives)->toContain('fastcgi_cache_valid 200 30m;');
    expect($directives)->toContain('fastcgi_cache_min_uses 3;');
    expect($directives)->toContain('$cookie_laravel_session');
});

test('a method the site is not eligible for is rejected', function (): void {
    [$user, $server, $site] = siteCachingWorkspaceFixtures();

    // Server runs nginx, so lscache (OpenLiteSpeed-only) must not be settable.
    expect($site->availableCachingMethods())->not->toContain('lscache');

    Livewire::actingAs($user)
        ->test(Caching::class, ['server' => $server, 'site' => $site])
        ->call('toggleMethod', 'lscache')
        ->assertSet('methods', []);
});

test('methods are inert until the master toggle is on', function (): void {
    Queue::fake();
    [$user, $server, $site] = siteCachingWorkspaceFixtures();

    Livewire::actingAs($user)
        ->test(Caching::class, ['server' => $server, 'site' => $site])
        ->set('enabled', false)
        ->call('toggleMethod', 'nginx_http')
        ->call('save');

    $site->refresh();

    expect($site->cachingConfig()['methods'])->toContain('nginx_http');
    expect($site->hasCachingMethod('nginx_http'))->toBeFalse();
    expect(app(SiteCacheDirectivesBuilder::class)->nginxFastcgiDirectives($site))->toBe('');
});

test('varnish panel exposes no per-site ttl input', function (): void {
    [$user, $server, $site] = siteCachingWorkspaceFixtures();

    // Varnish has one server-wide VCL — a per-site TTL box would save a value
    // nothing reads, so the panel must stay read-only.
    Livewire::actingAs($user)
        ->test(Caching::class, ['server' => $server, 'site' => $site])
        ->set('enabled', true)
        ->call('toggleMethod', 'varnish')
        ->assertDontSeeHtml('wire:model="varnish_ttl_default"');
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function siteCachingWorkspaceFixtures(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm', 'webserver' => 'nginx'],
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $server, $site];
}
