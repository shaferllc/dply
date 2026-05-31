<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteCachingPreviewTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.site_caching', fn (): bool => false);
    Feature::define('workspace.site_caching_preview', fn (): bool => true);
    Feature::flushCache();
});

test('site workspace sidebar shows caching with soon badge when preview active', function (): void {
    [$user, $server, $site] = siteCachingPreviewFixtures();

    $this->actingAs($user)
        ->get(route('sites.cron', [$server, $site]))
        ->assertOk()
        ->assertSee(__('Caching'))
        ->assertSee(__('Soon'))
        ->assertSee(route('sites.caching', [$server, $site]), false);
});

test('site caching route renders coming soon panel in site workspace shell', function (): void {
    [$user, $server, $site] = siteCachingPreviewFixtures();

    $this->actingAs($user)
        ->get(route('sites.caching', [$server, $site]))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Site cache layers'))
        ->assertSee(__('Nginx HTTP cache'))
        ->assertDontSee(__('Enable caching for this site'));
});

test('admin vm sites page lists site caching preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.sites'))
        ->assertOk()
        ->assertSee('workspace.site_caching_preview')
        ->assertSee(__('Coming soon preview'));
});

test('site caching route is hidden when preview and full feature are off', function (): void {
    Feature::define('workspace.site_caching_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server, $site] = siteCachingPreviewFixtures();

    $this->actingAs($user)
        ->get(route('sites.caching', [$server, $site]))
        ->assertNotFound();
});

test('site workspace hides caching when preview and full feature are off', function (): void {
    Feature::define('workspace.site_caching_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server, $site] = siteCachingPreviewFixtures();

    $ids = collect(SiteSettingsSidebar::items($site->fresh(), $server))->pluck('id')->all();

    expect($ids)->not->toContain('caching');
});

test('site caching preview respects per-org override', function (): void {
    [$user, $server, $site] = siteCachingPreviewFixtures();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.site_caching_preview');

    expect(workspace_site_caching_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('sites.caching', [$server, $site]))
        ->assertNotFound();
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function siteCachingPreviewFixtures(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
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
