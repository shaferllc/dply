<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFleetAlertBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_banner_hidden_when_fleet_is_clean(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    public function test_banner_shows_when_failed_latest_deploy_exists(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    public function test_banner_shows_when_long_running_deploy_exists(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    public function test_banner_shows_when_engine_drift_exists(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
    }

    public function test_banner_only_counts_current_org(): void
    {
        [$user, $org] = $this->makeUserOrg();
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
