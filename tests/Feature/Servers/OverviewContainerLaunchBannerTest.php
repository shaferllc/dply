<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Livewire\Servers\WorkspaceOverview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 4 of the container flow inversion. /servers/{id}/overview gains:
 *   - empty-state "Add your first container app" CTA when the host is
 *     docker/k8s, has zero sites, and no in-flight launch.
 *   - the existing container-launch banner is unchanged but verified here
 *     to ensure CTA + banner don't both render at the same time.
 */
final class OverviewContainerLaunchBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_docker_host_with_no_sites_shows_add_first_container_cta(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->assertSee('Add your first container app')
            ->assertSeeHtml('data-testid="add-first-container-cta"');
    }

    public function test_kubernetes_host_with_no_sites_shows_add_first_container_cta(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->kubernetesServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->assertSeeHtml('data-testid="add-first-container-cta"');
    }

    public function test_vm_host_does_not_show_container_cta(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->vmServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->assertDontSeeHtml('data-testid="add-first-container-cta"')
            ->assertDontSee('Add your first container app');
    }

    public function test_container_cta_is_hidden_when_launch_is_in_flight(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);
        $server->forceFill(['meta' => array_merge((array) $server->meta, [
            'container_launch' => [
                'status' => 'waiting_for_server',
                'target_family' => 'cloud_docker',
                'current_step_label' => 'Provisioning server',
                'summary' => 'Provisioning…',
                'events' => [],
            ],
        ])])->save();

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->assertDontSeeHtml('data-testid="add-first-container-cta"')
            ->assertSeeHtml('data-testid="container-launch-progress"');
    }

    public function test_container_cta_is_hidden_when_a_site_already_exists(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);
        Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceOverview::class, ['server' => $server])
            ->assertDontSeeHtml('data-testid="add-first-container-cta"');
    }

    private function dockerServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
        ]);
    }

    private function kubernetesServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => ['provider' => 'digitalocean', 'cluster_name' => 'c', 'namespace' => 'default'],
            ],
        ]);
    }

    private function vmServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
        ]);
    }

    private function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $organization->users()->attach($user->id, ['role' => 'owner']);
        $user->setRelation('currentOrganization', $organization);
        session(['current_organization_id' => $organization->id]);

        return $user;
    }
}
