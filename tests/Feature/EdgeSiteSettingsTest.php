<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\EdgeDeployment;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        ->assertSee('Billing & usage')
        ->assertSee('Traffic & analytics')
        ->assertSee('Build & deploy logs')
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

test('edge billing section shows usage stats and org analytics link', function () {
    config(['dply.edge.usage_billing.enabled' => true]);

    [$user, $server, $site] = makeEdgeSiteForSettings();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => 42_000,
        'bytes_egress' => 512 * 1024 * 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-billing'])
        ->assertSee('Billing & usage')
        ->assertSee('Platform fee')
        ->assertSee('42,000')
        ->assertSee('Open billing analytics');

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-billing']))
        ->assertOk()
        ->assertSee(route('billing.analytics', $site->organization_id), false);
});

test('edge traffic section shows request and bandwidth stats', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => 12_500,
        'bytes_egress' => 256 * 1024 * 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-traffic'])
        ->assertSee('Traffic & analytics')
        ->assertSee('Requests (MTD)')
        ->assertSee('Requests (7d)')
        ->assertSee('12,500')
        ->assertSee('Performance')
        ->assertSee('Core Web Vitals')
        ->assertSee('HTTP access logs')
        ->assertSee('Build & deploy logs');
});

test('edge logs section clarifies build logs vs visitor traffic', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-logs'])
        ->assertSee('Build & deploy logs')
        ->assertSee('not visitor HTTP logs')
        ->assertSee('Traffic & analytics')
        ->assertSee('Edge observability');
});

test('edge build settings can be updated on build settings section', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-build'])
        ->set('edge_build_command', 'pnpm install && pnpm build')
        ->set('edge_output_dir', 'out')
        ->set('edge_spa_fallback', false)
        ->set('edge_deploy_on_push', false)
        ->call('saveEdgeBuildSettings')
        ->assertHasNoErrors();

    $site->refresh();
    $edge = $site->edgeMeta();

    expect($edge['build']['command'] ?? null)->toBe('pnpm install && pnpm build')
        ->and($edge['build']['output_dir'] ?? null)->toBe('out')
        ->and($edge['routing']['spa_fallback'] ?? null)->toBeFalse()
        ->and($edge['source']['deploy_on_push'] ?? null)->toBeFalse();
});

test('edge deploy ref picker loads branches tags and commits from git provider', function () {
    Http::fake([
        'api.github.com/repos/acme/web' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/web/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
            ['name' => 'develop', 'commit' => ['sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']],
        ]),
        'api.github.com/repos/acme/web/tags*' => Http::response([
            ['name' => 'v1.0.0', 'commit' => ['sha' => 'cccccccccccccccccccccccccccccccccccccccc']],
        ]),
        'api.github.com/repos/acme/web/commits*' => Http::response([
            [
                'sha' => 'dddddddddddddddddddddddddddddddddddddddd',
                'commit' => [
                    'message' => 'Fix homepage hero',
                    'author' => ['name' => 'Dev', 'email' => 'dev@example.com'],
                    'committer' => ['date' => now()->toIso8601String()],
                ],
                'html_url' => 'https://github.com/acme/web/commit/dddddddd',
            ],
        ]),
    ]);

    [$user, $server, $site] = makeEdgeSiteForSettings(withGithub: true);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-deploys'])
        ->call('openEdgeDeployRefPicker')
        ->assertSet('edge_deploy_ref_picker_open', true)
        ->assertSee('Fix homepage hero')
        ->call('setEdgeDeployRefTab', 'branches')
        ->assertSee('develop')
        ->call('setEdgeDeployRefTab', 'tags')
        ->assertSee('v1.0.0')
        ->call('selectEdgeDeployRef', 'cccccccccccccccccccccccccccccccccccccccc')
        ->assertSet('edge_deploy_commit_sha', 'cccccccccccccccccccccccccccccccccccccccc')
        ->assertSet('edge_deploy_ref_picker_open', false);
});

test('edge billing card links to the site organization analytics page', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']))
        ->assertOk()
        ->assertSee('View stats');
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeEdgeSiteForSettings(bool $withGithub = false): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    if ($withGithub) {
        $user->socialAccounts()->create([
            'provider' => 'github',
            'provider_id' => '12345',
            'nickname' => 'edge-dev',
            'access_token' => 'gh-test-token',
        ]);
    }

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
