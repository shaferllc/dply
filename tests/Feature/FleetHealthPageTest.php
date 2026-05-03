<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetHealthPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_clean_state_when_nothing_wrong(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
            ->assertSee('1', false); // server count
    }

    public function test_surfaces_engine_drift(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    public function test_surfaces_failed_latest_deploys(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    public function test_long_running_deploy_count_renders(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    public function test_fleet_link_renders_in_top_nav(): void
    {
        [$user, $org] = $this->makeUserOrg();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(route('fleet.health'), false);
    }

    public function test_only_shows_servers_in_current_org(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function makeUserOrg(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return [$user, $org];
    }
}
