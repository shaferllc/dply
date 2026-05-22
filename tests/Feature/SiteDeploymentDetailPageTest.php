<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeploymentDetailPageTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('renders phase tree for a deployment', function () {
    [$user, $server, $site] = makeUserSite();
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinutes(2),
        'finished_at' => now(),
    ]);
    $deployment->recordPhaseResults('build', [
        ['step_id' => '1', 'step_type' => 'install', 'command' => 'composer install', 'ok' => true, 'output' => '', 'duration_ms' => 1234],
    ]);
    $deployment->recordPhaseResults('release', [
        ['step_id' => '2', 'step_type' => 'release', 'command' => 'php artisan migrate --force', 'ok' => true, 'output' => '', 'duration_ms' => 567],
    ]);

    $response = $this->actingAs($user)->get(route('sites.deployments.show', [
        'server' => $server,
        'site' => $site,
        'deployment' => $deployment,
    ]));

    $response->assertOk()
        ->assertSee('Deployment')
        ->assertSee($deployment->id)
        ->assertSee('build')
        ->assertSee('release')
        ->assertSee('composer install')
        ->assertSee('php artisan migrate --force')
        // CLI hint footer is present.
        ->assertSee('dply:site:show-deploy');
});
test('renders friendly message when no phase results', function () {
    [$user, $server, $site] = makeUserSite();
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('sites.deployments.show', [
        'server' => $server,
        'site' => $site,
        'deployment' => $deployment,
    ]));

    $response->assertOk()
        ->assertSee('No phase results recorded');
});
test('aborts when deployment belongs to different site', function () {
    [$user, $server, $site] = makeUserSite();
    $otherSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $site->organization_id,
    ]);
    $deployment = SiteDeployment::query()->create([
        'site_id' => $otherSite->id,
        'project_id' => $otherSite->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('sites.deployments.show', [
        'server' => $server,
        'site' => $site,
        'deployment' => $deployment,
    ]));

    $response->assertNotFound();
});
test('aborts when user is not in org', function () {
    [$ownerUser, $server, $site] = makeUserSite();
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    // Different user, different org context.
    $stranger = User::factory()->create();
    $strangerOrg = Organization::factory()->create();
    $strangerOrg->users()->attach($stranger->id, ['role' => 'owner']);
    session(['current_organization_id' => $strangerOrg->id]);

    $response = $this->actingAs($stranger)->get(route('sites.deployments.show', [
        'server' => $server,
        'site' => $site,
        'deployment' => $deployment,
    ]));

    $response->assertNotFound();
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
        'meta' => ['webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);

    return [$user, $server, $site];
}
