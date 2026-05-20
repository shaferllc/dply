<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Edge\Index as EdgeIndex;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class EdgeIndexPageTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['surface.edge'];

    public function test_empty_state_with_warning_when_no_credentials(): void
    {
        $user = $this->ownerWithOrg();

        $response = $this->actingAs($user)->get(route('edge.index'));

        $response->assertOk()
            ->assertSee('Edge sites')
            ->assertSee('No container backend connected')
            ->assertSee('No edge sites found');
    }

    public function test_lists_only_container_sites_for_current_org(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $otherOrg = Organization::factory()->create();

        $this->makeContainerSite($user, $org, 'My Container', 'digitalocean_app_platform');
        $this->makeContainerSite($user, $otherOrg, 'Other Org Container', 'digitalocean_app_platform');
        $this->makeVmSite($user, $org, 'PHP Site');

        $response = $this->actingAs($user)->get(route('edge.index'));

        $response->assertOk()
            ->assertSee('My Container')
            ->assertDontSee('Other Org Container')
            ->assertDontSee('PHP Site');
    }

    public function test_filter_by_backend(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'DO Site', 'digitalocean_app_platform');
        $this->makeContainerSite($user, $org, 'AWS Site', 'aws_app_runner');

        Livewire::actingAs($user)
            ->test(EdgeIndex::class)
            ->set('filter', 'aws_app_runner')
            ->assertSee('AWS Site')
            ->assertDontSee('DO Site');
    }

    public function test_filter_by_failed_status(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'Healthy', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
        $this->makeContainerSite($user, $org, 'Broken', 'digitalocean_app_platform', Site::STATUS_CONTAINER_FAILED);

        Livewire::actingAs($user)
            ->test(EdgeIndex::class)
            ->set('filter', 'failed')
            ->assertSee('Broken')
            ->assertDontSee('Healthy');
    }

    public function test_status_pill_renders_for_active_site_with_live_url(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $site = $this->makeContainerSite($user, $org, 'Live App', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
        $site->update(['meta' => ['container' => ['live_url' => 'https://live-app.ondigitalocean.app']]]);

        $response = $this->actingAs($user)->get(route('edge.index'));

        $response->assertSee('Active')
            ->assertSee('live-app.ondigitalocean.app');
    }

    public function test_warning_hides_when_a_backend_credential_is_connected(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => 'digitalocean_app_platform',
            'name' => 'DO',
            'credentials' => ['api_token' => 't'],
        ]);

        $response = $this->actingAs($user)->get(route('edge.index'));

        $response->assertDontSee('No container backend connected');
    }

    public function test_lists_source_mode_site_with_repo_branch(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'Source Site',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        ]);

        $response = $this->actingAs($user)->get(route('edge.index'));

        $response->assertOk()
            ->assertSee('Source Site')
            ->assertSee('acme/api@main')
            ->assertSee('Image / source');
    }

    public function test_lists_preview_with_pr_badge(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'PR #42 — API',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => [
                    'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                    'preview_branch' => 'feature/x',
                    'preview_pr_number' => 42,
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('edge.index'));

        $response->assertOk()
            ->assertSee('PR #42');
    }

    public function test_filter_source_only_shows_source_sites(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'image-only', 'digitalocean_app_platform');

        $sourceServer = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        Site::factory()->create([
            'server_id' => $sourceServer->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'source-only',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        ]);

        Livewire::actingAs($user)
            ->test(EdgeIndex::class)
            ->set('filter', 'source')
            ->assertSee('source-only')
            ->assertDontSee('image-only');
    }

    public function test_filter_previews_only_shows_preview_sites(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        $parent = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'parent-prod',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => ['container' => ['source' => ['repo' => 'acme/api', 'branch' => 'main']]],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'pr-preview',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => [
                    'source' => ['repo' => 'acme/api', 'branch' => 'feature/x'],
                    'preview_parent_site_id' => $parent->id,
                    'preview_branch' => 'feature/x',
                ],
            ],
        ]);

        Livewire::actingAs($user)
            ->test(EdgeIndex::class)
            ->set('filter', 'previews')
            ->assertSee('pr-preview')
            ->assertDontSee('parent-prod');
    }

    public function test_filter_counts_match_actual_sites(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();
        $this->makeContainerSite($user, $org, 'A', 'digitalocean_app_platform', Site::STATUS_CONTAINER_ACTIVE);
        $this->makeContainerSite($user, $org, 'B', 'digitalocean_app_platform', Site::STATUS_CONTAINER_PROVISIONING);
        $this->makeContainerSite($user, $org, 'C', 'aws_app_runner', Site::STATUS_CONTAINER_FAILED);

        Livewire::actingAs($user)
            ->test(EdgeIndex::class)
            ->assertViewHas('totals', fn ($t): bool => $t['all'] === 3
                && $t['digitalocean_app_platform'] === 2
                && $t['aws_app_runner'] === 1
                && $t['failed'] === 1
                && $t['provisioning'] === 1);
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

    private function makeVmSite(User $user, Organization $org, string $name): Site
    {
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => $name,
            'type' => SiteType::Php,
        ]);
    }
}
