<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class FleetDeploysPageTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['surface.fleet'];

    public function test_running_tab_shows_in_flight_deploys(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'in-flight']);
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->get(route('fleet.deploys'));

        $response->assertOk()
            ->assertSee('Fleet deploys')
            ->assertSee('in-flight');
    }

    public function test_failed_latest_tab(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'broken']);
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_FAILED,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->get(route('fleet.deploys').'?tab=failed-latest');

        $response->assertOk()
            ->assertSee('broken');
    }

    public function test_stale_tab_with_custom_threshold(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $stale = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'stale-app']);
        $fresh = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'fresh-app']);
        SiteDeployment::query()->create([
            'site_id' => $stale->id,
            'project_id' => $stale->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subDays(60),
            'finished_at' => now()->subDays(60),
        ]);
        SiteDeployment::query()->create([
            'site_id' => $fresh->id,
            'project_id' => $fresh->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($user)->get(route('fleet.deploys').'?tab=stale&days=30');

        $response->assertOk()
            ->assertSee('stale-app')
            ->assertDontSee('fresh-app');
    }

    public function test_friendly_empty_state_per_tab(): void
    {
        [$user] = $this->makeUserOrg();

        $response = $this->actingAs($user)->get(route('fleet.deploys').'?tab=running');
        $response->assertOk()->assertSee('No deploys are currently running');

        $response = $this->actingAs($user)->get(route('fleet.deploys').'?tab=failed-latest');
        $response->assertOk()->assertSee('No sites have a failed latest deploy');

        $response = $this->actingAs($user)->get(route('fleet.deploys').'?tab=stale&days=7');
        $response->assertOk()->assertSee('threshold: 7 days');
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
