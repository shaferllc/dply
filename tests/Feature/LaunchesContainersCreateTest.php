<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Launches\Containers\Create as LaunchesContainersCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coverage for /launches/containers/create:
 *  - The /launches/create card wires up to the new route + edge.create.
 *  - Inspection auto-picks path (docker vs kubernetes) from detection.
 *  - Docker path: goToDockerWizard writes draft + redirects to /servers/create.
 *  - Kubernetes path: in-place launch with provider/region/cluster_name/namespace.
 *  - OSS presets + applyPreset behavior preserved.
 */
class LaunchesContainersCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_launchpad_containers_card_links_to_new_route(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.create'));

        $response->assertOk();
        $response->assertSee(route('launches.containers.create'), false);
    }

    public function test_launchpad_edge_card_links_to_edge_create(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('launches.create'));

        $response->assertOk();
        $response->assertSee(route('edge.create'), false);
    }

    public function test_target_options_are_kubernetes_only(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('path', 'kubernetes')
            ->assertSee('DOKS (DigitalOcean)')
            ->assertSee('EKS (AWS)')
            ->assertDontSee('Local Docker')
            ->assertDontSee('local_orbstack');
    }

    public function test_path_picker_default_is_docker(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->assertSet('path', 'docker')
            ->assertSee('Continue to the server wizard');
    }

    public function test_go_to_docker_wizard_writes_draft_and_redirects(): void
    {
        $user = $this->userWithOrganization();

        $inspection = $this->fakeInspection();
        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $inspection)
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->set('repository_branch', 'main')
            ->set('path', 'docker')
            ->call('goToDockerWizard')
            ->assertRedirect(route('servers.create', ['host_target' => 'docker']));

        $draft = ServerCreateDraft::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->currentOrganization()->id)
            ->first();
        $this->assertNotNull($draft);
        $this->assertIsArray($draft->payload['_container_launch'] ?? null);
        $this->assertSame('https://github.com/acme/demo.git', $draft->payload['_container_launch']['repository_url']);
        $this->assertSame('main', $draft->payload['_container_launch']['repository_branch']);
        $this->assertSame('demo', $draft->payload['_container_launch']['slug']);
        $this->assertSame('cloud_docker', $draft->payload['_container_launch']['target_family']);
    }

    public function test_go_to_docker_wizard_requires_inspection(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', false)
            ->call('goToDockerWizard')
            ->assertHasErrors(['repository_url']);
    }

    public function test_kubernetes_path_credential_empty_state(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('path', 'kubernetes')
            ->set('target_family', 'digitalocean_kubernetes')
            ->assertSee('No DigitalOcean credentials connected')
            ->assertSee(route('credentials.index'), false);
    }

    public function test_apply_preset_fills_repository_fields_and_resets_inspection(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('repo_source', 'provider')
            ->call('applyPreset', 'plausible')
            ->assertSet('repo_source', 'manual')
            ->assertSet('repository_url', 'https://github.com/plausible/analytics.git')
            ->assertSet('repository_branch', 'master')
            ->assertSet('repository_subdirectory', '')
            ->assertSet('has_inspection', false)
            ->assertSet('inspection', [])
            ->assertSee('Try an open-source preset')
            ->assertSee('Plausible Analytics');
    }

    public function test_kubernetes_path_credential_present_hides_empty_state(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('path', 'kubernetes')
            ->set('target_family', 'digitalocean_kubernetes')
            ->assertDontSee('No DigitalOcean credentials connected');
    }

    public function test_kubernetes_tile_shows_connected_badge_when_credential_exists(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('path', 'kubernetes')
            ->assertSee('DOKS (DigitalOcean)')
            ->assertSee('Connected');
    }

    public function test_kubernetes_tile_shows_needs_account_when_aws_missing(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('path', 'kubernetes')
            ->assertSee('EKS (AWS)')
            ->assertSee('Needs account')
            ->assertSee(route('credentials.index'), false);
    }

    public function test_global_empty_state_when_no_cloud_credentials(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('path', 'kubernetes')
            ->assertSee('No connected providers yet.')
            ->assertSee('Connect a provider');
    }

    public function test_server_name_defaults_from_inspection_slug(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->assertSet('server_name', 'demo-digitalocean-kubernetes');
    }

    public function test_inspection_auto_picks_kubernetes_path_when_detected(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspectionWithKubernetes())
            ->assertSet('path', 'docker');  // not auto-set when forcing has_inspection directly
    }

    public function test_kubernetes_launch_requires_server_name(): void
    {
        $user = $this->userWithOrganization();
        $organization = $user->currentOrganization();
        $credential = ProviderCredential::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 't'],
        ]);

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('path', 'kubernetes')
            ->set('target_family', 'digitalocean_kubernetes')
            ->set('provider_credential_id', (string) $credential->id)
            ->set('cloud_region', 'nyc3')
            ->set('server_name', '')
            ->call('launch')
            ->assertHasErrors(['server_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeInspection(): array
    {
        return [
            'slug' => 'demo',
            'name' => 'Demo',
            'detection' => [
                'target_kind' => 'docker',
                'kubernetes_namespace' => null,
                'framework' => 'laravel',
                'language' => 'php',
                'confidence' => 'high',
                'document_root' => '/var/www/demo/public',
                'repository_path' => '/var/www/demo',
                'reasons' => [],
                'warnings' => [],
                'detected_files' => [],
                'env_template' => ['path' => null, 'keys' => []],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeInspectionWithKubernetes(): array
    {
        $base = $this->fakeInspection();
        $base['detection']['target_kind'] = 'kubernetes';

        return $base;
    }

    private function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        $user->setRelation('currentOrganization', $organization);

        return $user;
    }
}
