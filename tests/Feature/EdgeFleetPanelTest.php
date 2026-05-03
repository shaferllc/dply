<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeFleetPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_hidden_when_no_edge_sites(): void
    {
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertOk()
            ->assertDontSee('edge container site');
    }

    public function test_panel_shows_total_count_with_link_to_index(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'A', 'digitalocean_app_platform');
        $this->makeContainerSite($user, $org, 'B', 'aws_app_runner');

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertOk()
            ->assertSee('2 edge container sites')
            ->assertSee('Open /edge')
            ->assertSee(route('edge.index'), escape: false);
    }

    public function test_panel_breakdown_by_backend(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'A', 'digitalocean_app_platform');
        $this->makeContainerSite($user, $org, 'B', 'digitalocean_app_platform');
        $this->makeContainerSite($user, $org, 'C', 'aws_app_runner');

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertSee('DO App Platform')
            ->assertSee('AWS App Runner');
    }

    public function test_panel_surfaces_failed_sites_in_red(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'Healthy', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
        $this->makeContainerSite($user, $org, 'Broken Container', 'digitalocean_app_platform', Site::STATUS_CONTAINER_FAILED);

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertSee('1 edge site failed')
            ->assertSee('Broken Container');
    }

    public function test_panel_replaces_old_fly_upsell_when_edge_sites_exist(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        // Have a Node site (would normally trigger old Fly upsell)
        // AND an edge site — the edge panel should win.
        $vmServer = Server::factory()->create(['organization_id' => $org->id]);
        Site::factory()->create([
            'server_id' => $vmServer->id,
            'organization_id' => $org->id,
            'runtime' => 'node',
        ]);
        $this->makeContainerSite($user, $org, 'My Edge', 'digitalocean_app_platform');

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertSee('1 edge container site')
            ->assertDontSee('Deploy a container app on dply edge'); // old upsell hidden
    }

    private function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    private function makeContainerSite(User $user, Organization $org, string $name, string $backend, string $status = Site::STATUS_CONTAINER_PROVISIONING): Site
    {
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => $name,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => $backend,
            'container_region' => 'nyc',
            'status' => $status,
        ]);
    }
}
