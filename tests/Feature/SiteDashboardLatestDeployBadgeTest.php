<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDashboardLatestDeployBadgeTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard shows last deploy badge when deployment exists', function () {
    [$user, $server, $site] = makeUserSite();
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-1',
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(5),
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Last deploy')
        ->assertSee('success')
        // Badge links to the deployment-detail page now.
        ->assertSee(route('sites.deployments.show', [
            'server' => $server,
            'site' => $site,
            'deployment' => $deployment,
        ]), false)
        // "All deploys" badge links to the deployments index.
        ->assertSee('All deploys')
        ->assertSee(route('sites.deployments.index', [
            'server' => $server,
            'site' => $site,
        ]), false);
});
test('dashboard omits badge when no deployments', function () {
    [$user, $server, $site] = makeUserSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertDontSee('Last deploy');
});
test('failed deploy renders in rose', function () {
    [$user, $server, $site] = makeUserSite();
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'idempotency_key' => 'dep-fail',
        'trigger' => 'webhook',
        'status' => SiteDeployment::STATUS_FAILED,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Last deploy')
        ->assertSee('failed')
        ->assertSeeHtml('bg-rose-100');
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeUserSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'webserver' => 'nginx',
            'php_inventory' => ['supported' => true, 'installed_versions' => ['8.4']],
        ],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    $site->refresh();

    return [$user, $server, $site];
}
