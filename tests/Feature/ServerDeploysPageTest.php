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

class ServerDeploysPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_deploys_for_all_sites_on_the_server(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->ready()->create(['organization_id' => $org->id]);
        $a = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'site-a']);
        $b = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'site-b']);
        SiteDeployment::query()->create([
            'site_id' => $a->id,
            'project_id' => $a->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ]);
        SiteDeployment::query()->create([
            'site_id' => $b->id,
            'project_id' => $b->project_id,
            'trigger' => 'webhook',
            'status' => SiteDeployment::STATUS_FAILED,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->get(route('servers.deploys', $server));

        $response->assertOk()
            ->assertSee('Deploys on')
            ->assertSee('site-a')
            ->assertSee('site-b');
    }

    public function test_status_filter(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->ready()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $failed = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_FAILED,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('servers.deploys', $server).'?status=failed');

        $response->assertOk()
            ->assertSee($failed->id);
    }

    public function test_does_not_show_deploys_from_sites_on_other_servers(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $serverA = Server::factory()->ready()->create(['organization_id' => $org->id, 'name' => 'a']);
        $serverB = Server::factory()->ready()->create(['organization_id' => $org->id, 'name' => 'b']);
        $siteA = Site::factory()->create(['server_id' => $serverA->id, 'organization_id' => $org->id, 'name' => 'on-a']);
        $siteB = Site::factory()->create(['server_id' => $serverB->id, 'organization_id' => $org->id, 'name' => 'on-b']);
        SiteDeployment::query()->create([
            'site_id' => $siteB->id,
            'project_id' => $siteB->project_id,
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('servers.deploys', $serverA));

        $response->assertOk()
            ->assertDontSee('on-b')
            ->assertSee('No deployments match');
    }

    public function test_aborts_when_user_is_not_in_org(): void
    {
        [, $server] = $this->makeUserOrgWithServer();
        $stranger = User::factory()->create();
        $strangerOrg = Organization::factory()->create();
        $strangerOrg->users()->attach($stranger->id, ['role' => 'owner']);
        session(['current_organization_id' => $strangerOrg->id]);

        $response = $this->actingAs($stranger)->get(route('servers.deploys', $server));

        $response->assertNotFound();
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

    /**
     * @return array{0: User, 1: Server}
     */
    private function makeUserOrgWithServer(): array
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->ready()->create(['organization_id' => $org->id]);

        return [$user, $server];
    }
}
