<?php

declare(strict_types=1);

namespace Tests\Feature\DashboardFleetAlertBannerTest;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('banner hidden when fleet is clean', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertDontSee('Fleet needs attention');
});
test('banner shows when failed latest deploy exists', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_FAILED,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Fleet needs attention')
        ->assertSee('failed latest deploy', false)
        ->assertSee(route('fleet.health'), false);
});
test('banner shows when long running deploy exists', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now()->subMinutes(30),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Fleet needs attention')
        ->assertSee('15 minutes', false);
});
test('banner shows when engine drift exists', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);

    // Site requests an engine the server hasn't registered.
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'database_engine' => 'mysql',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Fleet needs attention')
        ->assertSee('engine drift', false);
});
test('banner only counts current org', function () {
    [$user, $org] = makeUserOrg();

    // A separate org with a failed deploy — should NOT influence current org banner.
    $otherOrg = Organization::factory()->create();
    $otherServer = Server::factory()->create(['organization_id' => $otherOrg->id]);
    $otherSite = Site::factory()->create([
        'server_id' => $otherServer->id,
        'organization_id' => $otherOrg->id,
    ]);
    SiteDeployment::query()->create([
        'site_id' => $otherSite->id,
        'project_id' => $otherSite->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_FAILED,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertDontSee('Fleet needs attention');
});
/**
 * @return array{0: User, 1: Organization}
 */
function makeUserOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}
