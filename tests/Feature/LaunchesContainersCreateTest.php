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
 * Coverage for /launches/containers/create after the LocalDocker rename:
 *  - The /launches/create card wires up to the new route + edge.create.
 *  - Local target options gate behind launches.local_docker_enabled.
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

    public function test_dropdown_hides_local_options_when_flag_off(): void
    {
        config(['launches.local_docker_enabled' => false]);
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->assertDontSee('Local Docker')
            ->assertDontSee('Local Kubernetes')
            ->assertSee('Remote Docker (DigitalOcean)')
            ->assertSee('Remote Kubernetes (AWS)');
    }

    public function test_dropdown_shows_local_options_when_flag_on(): void
    {
        config(['launches.local_docker_enabled' => true]);
        $user = $this->userWithOrganization();

        Livewire::actingAs($user)
            ->test(LaunchesContainersCreate::class)
            ->set('has_inspection', true)
            ->set('inspection', $this->fakeInspection())
            ->assertSee('Local Docker (testing only)')
            ->assertSee('Local Kubernetes (testing only)');
    }

    public function test_validation_rejects_local_target_when_flag_off(): void
    {
        config(['launches.local_docker_enabled' => false]);
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
