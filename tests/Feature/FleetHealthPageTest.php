<?php

declare(strict_types=1);

namespace Tests\Feature\FleetHealthPageTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

test('renders clean state when nothing wrong', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
        'database_engine' => 'postgres',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('Fleet health')
        ->assertSee('All clear')
        ->assertSee('1', false);
    // server count
});
test('surfaces engine drift', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    // Site requests an engine the server doesn't have registered.
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'name' => 'misconfigured-app',
        'database_engine' => 'mysql',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('Drift detected')
        ->assertSee('misconfigured-app')
        ->assertSee('mysql');
});
test('surfaces failed latest deploys', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'name' => 'broken-app',
        'runtime' => 'php',
    ]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_FAILED,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('failed latest deploy', false)
        ->assertSee('broken-app')
        ->assertDontSee('All clear');
});
test('long running deploy count renders', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
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
        'started_at' => now()->subMinutes(30),
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('Running deploys')
        ->assertSee('longer than 15m');
});
test('fly upsell shows for orgs with node sites and no fly credential', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'node',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('dply cloud')
        ->assertSee('Deploy to dply cloud');
});
test('fly upsell hides when org has only php sites', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertDontSee('Deploy a container app on dply cloud');
});
test('fly upsell hides when org already has fly credential', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'runtime' => 'node',
    ]);
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'fly_io',
        'name' => 'Existing Fly token',
        'credentials' => ['api_token' => 'fly-token-test'],
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertDontSee('Deploy a container app on dply cloud');
});
test('fleet link renders in top nav', function () {
    [$user, $org] = makeUserOrg();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee(route('fleet.health'), false);
});
test('renders 7 day success rate', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
    for ($i = 0; $i < 4; $i++) {
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subDays($i),
            'finished_at' => now()->subDays($i),
        ]);
    }
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_FAILED,
        'started_at' => now()->subDays(2),
        'finished_at' => now()->subDays(2),
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('7-day success', false)
        ->assertSee('80%')
        ->assertSee('4 / 5');
});
test('success rate renders no deploys yet when empty', function () {
    [$user] = makeUserOrg();

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('No deploys yet');
});
test('most active sites panel renders top deployers', function () {
    [$user, $org] = makeUserOrg();
    $server = Server::factory()->create(['organization_id' => $org->id]);
    $busy = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'busy-app']);
    $quiet = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'quiet-app']);
    for ($i = 0; $i < 5; $i++) {
        SiteDeployment::query()->create([
            'site_id' => $busy->id,
            'project_id' => $busy->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subDays($i),
            'finished_at' => now()->subDays($i),
        ]);
    }
    SiteDeployment::query()->create([
        'site_id' => $quiet->id,
        'project_id' => $quiet->project_id,
        'trigger' => 'manual',
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subDays(2),
        'finished_at' => now()->subDays(2),
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('Most active sites')
        ->assertSee('busy-app')
        ->assertSee('5 deploys')
        ->assertSee('quiet-app')
        ->assertSee('1 deploys');
});
test('only shows servers in current org', function () {
    [$user, $org] = makeUserOrg();
    $otherOrg = Organization::factory()->create();
    Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'mine',
    ]);
    Server::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'theirs',
    ]);

    $response = $this->actingAs($user)->get(route('fleet.health'));

    $response->assertOk()
        ->assertSee('Fleet health')
        // Server count widget should reflect the current org's count (1).
        ->assertDontSee('theirs');
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
