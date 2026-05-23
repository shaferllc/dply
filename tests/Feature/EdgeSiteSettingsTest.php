<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('edge site settings sidebar shows edge sections not byo runtime', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Overview')
        ->assertSee('Deploys')
        ->assertSee('Build settings')
        ->assertSee('Domains')
        ->assertSee('Logs & activity')
        ->assertSee('Back to Edge sites')
        ->assertDontSee('System user')
        ->assertDontSee('Runtime')
        ->assertDontSee('Certificates')
        ->assertDontSee('DNS');
});

test('edge overview shows live url redeploy and no nginx references', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Edge App')
        ->assertSee('https://edge-app.dply.host')
        ->assertSee('Redeploy')
        ->assertSee('Open live site')
        ->assertSee('acme/web')
        ->assertSee('Deploy history')
        ->assertDontSee('Dply Edge')
        ->assertDontSee('nginx')
        ->assertDontSee('Webserver')
        ->assertDontSee('PHP-FPM');
});

test('edge breadcrumbs use infrastructure and edge not servers path', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']))
        ->assertOk()
        ->assertSee('Infrastructure')
        ->assertSee('Edge')
        ->assertSee('Edge site workspace');
});

test('edge deploys section renders deploy history table', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-deploys'])
        ->assertSee('Deploy history')
        ->assertSee('Roll back');
});

test('edge danger section shows delete edge site not nginx teardown', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'danger'])
        ->assertSee('Delete Edge site')
        ->assertDontSee('Nginx vhost')
        ->assertDontSee('Suspend public site');
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeEdgeSiteForSettings(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'live_url' => 'https://edge-app.dply.host',
                'deploy_on_push' => true,
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/test/prefix',
        'published_at' => now(),
    ]);

    return [$user, $server, $site];
}
