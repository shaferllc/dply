<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class CloudFleetPanelTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['surface.cloud', 'surface.fleet'];

    public function test_panel_hidden_when_no_cloud_sites(): void
    {
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertOk()
            ->assertDontSee('cloud container site');
    }

    public function test_panel_shows_total_count_with_link_to_index(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'A', 'digitalocean_app_platform');
        $this->makeContainerSite($user, $org, 'B', 'aws_app_runner');

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertOk()
            ->assertSee('2 cloud container sites')
            ->assertSee('Open /cloud')
            ->assertSee(route('cloud.index'), escape: false);
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

        $response->assertSee('1 cloud site failed')
            ->assertSee('Broken Container');
    }

    public function test_panel_shows_source_mode_and_preview_breakdown(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        // Two source-mode parents + one preview deploy.
        $this->makeContainerSite($user, $org, 'src-1', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
            'container_image' => null,
        ]);
        $parent = $this->makeContainerSite($user, $org, 'src-2', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE, [
            'meta' => ['container' => ['source' => ['repo' => 'acme/web', 'branch' => 'main']]],
            'container_image' => null,
        ]);
        $this->makeContainerSite($user, $org, 'preview-pr-9', 'digitalocean_app_platform', Site::STATUS_CONTAINER_PROVISIONING, [
            'container_image' => null,
            'meta' => [
                'container' => [
                    'source' => ['repo' => 'acme/web', 'branch' => 'feature/x'],
                    'preview_parent_site_id' => $parent->id,
                    'preview_branch' => 'feature/x',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertOk()
            ->assertSee('3 source-mode sites')
            ->assertSee('1 preview deploy');
    }

    public function test_panel_replaces_old_fly_upsell_when_cloud_sites_exist(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        // Have a Node site (would normally trigger old Fly upsell)
        // AND a cloud site — the cloud panel should win.
        $vmServer = Server::factory()->create(['organization_id' => $org->id]);
        Site::factory()->create([
            'server_id' => $vmServer->id,
            'organization_id' => $org->id,
            'runtime' => 'node',
        ]);
        $this->makeContainerSite($user, $org, 'My Edge', 'digitalocean_app_platform');

        $response = $this->actingAs($user)->get(route('fleet.health'));

        $response->assertSee('1 cloud container site')
            ->assertDontSee('Deploy a container app on dply cloud'); // old upsell hidden
    }

    private function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContainerSite(User $user, Organization $org, string $name, string $backend, string $status = Site::STATUS_CONTAINER_PROVISIONING, array $overrides = []): Site
    {
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
        ]);

        return Site::factory()->create(array_merge([
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
        ], $overrides));
    }
}
