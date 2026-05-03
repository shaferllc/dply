<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coverage for the dashboard "Fetch deployments" button. The
 * button calls fetchContainerDeployments() on the
 * ManagesContainerSite trait, which delegates to
 * backend->recentDeployments and stores the result on
 * container_deployments_result. The view branches on whether
 * the array is empty, populated, or null (not yet fetched).
 */
class EdgeDeploymentsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['server_provision_fake.env_flag' => true]);
    }

    public function test_button_renders_on_dashboard(): void
    {
        [$user, $server, $site] = $this->scaffoldSite();

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Recent deployments')
            ->assertSee('Fetch deployments');
    }

    public function test_fetch_populates_synthetic_entries_via_fake_backend(): void
    {
        [$user, $server, $site] = $this->scaffoldSite();

        $component = Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->assertSet('container_deployments_result', null)
            ->call('fetchContainerDeployments');

        $set = $component->get('container_deployments_result');
        $this->assertIsArray($set);
        $this->assertGreaterThanOrEqual(1, count($set));
        $this->assertSame('ACTIVE', (string) $set[0]['phase']);
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function scaffoldSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'API service',
            'slug' => 'api-service',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'nginx:1',
            'container_port' => 80,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'container_backend_id' => null,
            'status' => Site::STATUS_CONTAINER_ACTIVE,
        ]);

        return [$user, $server, $site];
    }
}
