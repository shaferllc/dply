<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Launches\Containers\Create as LaunchesContainersCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coverage for /launches/containers/create:
 *  - The /launches/create card wires up to the new route + edge.create.
 *  - Only cloud targets are exposed (local OrbStack targets were retired).
 *  - The form renders an empty-state "Connect …" notice when no
 *    credential exists for the selected cloud target.
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

    public function test_target_options_are_cloud_only(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->assertDontSee('Local Docker')
            ->assertDontSee('Local Kubernetes')
            ->assertDontSee('local_orbstack')
            ->assertSee('Remote Docker (DigitalOcean)')
            ->assertSee('Remote Kubernetes (AWS)');
    }

    public function test_validation_rejects_local_target_family(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('repository_url', 'https://github.com/acme/demo.git')
            ->set('target_family', 'local_orbstack_docker')
            ->call('launch')
            ->assertHasErrors(['target_family']);
    }

    public function test_empty_state_connect_link_when_digitalocean_credential_missing(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('target_family', 'digitalocean_docker')
            ->assertSee('No DigitalOcean credentials connected')
            ->assertSee(route('credentials.index'), false);
    }

    public function test_empty_state_connect_link_when_aws_credential_missing(): void
    {
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->set('target_family', 'aws_docker')
            ->assertSee('No AWS credentials connected')
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

    public function test_empty_state_hidden_when_credential_present(): void
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
            ->set('target_family', 'digitalocean_docker')
            ->assertDontSee('No DigitalOcean credentials connected');
    }

    public function test_target_tile_shows_connected_badge_when_credential_exists(): void
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
            ->assertSee('Remote Docker (DigitalOcean)')
            ->assertSee('Connected');
    }

    public function test_target_tile_shows_needs_account_badge_when_credential_missing(): void
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
            ->assertSee('Remote Docker (AWS)')
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
            ->assertSet('server_name', 'demo-digitalocean-docker');
    }

    public function test_server_name_is_required_on_launch(): void
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
            ->set('target_family', 'digitalocean_docker')
            ->set('provider_credential_id', (string) $credential->id)
            ->set('cloud_region', 'nyc3')
            ->set('cloud_size', 's-1vcpu-1gb')
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

    private function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        $user->setRelation('currentOrganization', $organization);

        return $user;
    }
}
