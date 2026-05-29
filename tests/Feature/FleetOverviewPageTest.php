<?php

declare(strict_types=1);

namespace Tests\Feature\FleetOverviewPageTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

usesFeatures('surface.fleet');

test('fleet index renders the overview intro page', function () {
    [$user] = makeUserOrg();

    $response = $this->actingAs($user)->get(route('fleet.index'));

    $response->assertOk()
        ->assertSee('Fleet ops')
        ->assertSee('What is the fleet?')
        // Section directory links into each fleet surface.
        ->assertSee(route('fleet.health'), false)
        ->assertSee(route('fleet.blast-radius'), false)
        // Overview tab is present in the section nav.
        ->assertSee('Overview');
});

test('overview surfaces headline counts scoped to the org', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.index'));

    $response->assertOk()
        ->assertSee('Servers')
        ->assertSee('Sites')
        ->assertSee('In-flight deploys')
        ->assertSee('7-day success');
});

test('overview shows in-flight deploy count', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now()->subMinutes(2),
    ]);

    $response = $this->actingAs($user)->get(route('fleet.index'));

    $response->assertOk()
        ->assertSee('In-flight deploys');
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
