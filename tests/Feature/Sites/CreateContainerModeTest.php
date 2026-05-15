<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Enums\SiteType;
use App\Jobs\FinalizeContainerCloudLaunchJob;
use App\Livewire\Sites\Create as SiteCreate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Deploy\LocalRepositoryInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Per the container-flow inversion: Sites/Create auto-decides container
 * mode by server host_kind. For docker / kubernetes hosts the existing
 * VM-shaped form is replaced with a repo + namespace form that submits
 * via FinalizeContainerCloudLaunchJob — the same polling job that today's
 * launcher uses, just triggered from a per-server CTA.
 */
final class CreateContainerModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_docker_host_renders_container_mode_form(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->assertSee('Try an open-source preset')
            ->assertSee('Plausible Analytics')
            ->assertSee('Add container')
            ->assertSeeHtml('data-testid="sites-create-container-mode"')
            ->assertDontSee('1. Confirm server context');
    }

    public function test_vm_host_keeps_existing_form(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->vmServer($user);

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->assertDontSee('Try an open-source preset')
            ->assertDontSeeHtml('data-testid="sites-create-container-mode"');
    }

    public function test_oss_preset_fills_repository_url(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->call('applyContainerOssPreset', 'plausible')
            ->assertSet('container_repository_url', 'https://github.com/plausible/analytics.git')
            ->assertSet('container_repository_branch', 'master');
    }

    public function test_kubernetes_namespace_defaults_from_server_meta(): void
    {
        $user = $this->userWithOrganization();
        $server = $this->kubernetesServer($user, namespace: 'production');

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->assertSet('container_kubernetes_namespace', 'production');
    }

    public function test_store_container_dispatches_finalizer_job(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();
        $server = $this->dockerServer($user);

        $this->stubInspector();

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->set('container_repo_source', 'manual')
            ->set('container_repository_url', 'https://github.com/org/widget.git')
            ->set('container_repository_branch', 'main')
            ->call('storeContainer');

        Bus::assertDispatched(FinalizeContainerCloudLaunchJob::class, function ($job) use ($server) {
            return $job->serverId === (string) $server->id
                && $job->targetFamily === 'cloud_docker';
        });

        $server->refresh();
        $this->assertSame('queued', data_get($server->meta, 'container_launch.status'));
    }

    public function test_store_container_kubernetes_uses_per_app_namespace(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();
        $server = $this->kubernetesServer($user, namespace: 'production');

        $this->stubInspector(targetKind: 'kubernetes');

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->set('container_repo_source', 'manual')
            ->set('container_repository_url', 'https://github.com/org/widget.git')
            ->set('container_kubernetes_namespace', 'staging')
            ->call('storeContainer');

        Bus::assertDispatched(FinalizeContainerCloudLaunchJob::class, function ($job) {
            return $job->targetFamily === 'cloud_kubernetes'
                && data_get($job->inspection, 'detection.kubernetes_namespace') === 'staging';
        });
    }

    public function test_store_container_validates_kubernetes_namespace_format(): void
    {
        Bus::fake();

        $user = $this->userWithOrganization();
        $server = $this->kubernetesServer($user);

        $this->stubInspector(targetKind: 'kubernetes');

        Livewire::actingAs($user)
            ->test(SiteCreate::class, ['server' => $server])
            ->set('container_repo_source', 'manual')
            ->set('container_repository_url', 'https://github.com/org/widget.git')
            ->set('container_kubernetes_namespace', 'NOT_VALID')
            ->call('storeContainer')
            ->assertHasErrors('container_kubernetes_namespace');

        Bus::assertNotDispatched(FinalizeContainerCloudLaunchJob::class);
    }

    private function dockerServer(User $user): Server
    {
        return Server::factory()->ready()->create([
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
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

    private function kubernetesServer(User $user, string $namespace = 'default'): Server
    {
        return Server::factory()->ready()->create([
            'organization_id' => $user->currentOrganization()->id,
            'user_id' => $user->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
                'kubernetes' => [
                    'provider' => 'digitalocean',
                    'cluster_name' => 'prod-cluster',
                    'namespace' => $namespace,
                ],
            ],
        ]);
    }

    private function stubInspector(string $targetKind = 'docker'): void
    {
        $payload = [
            'repository_url' => 'https://github.com/org/widget.git',
            'repository_branch' => 'main',
            'repository_subdirectory' => '',
            'slug' => 'widget',
            'name' => 'Widget',
            'inspection_output' => '',
            'detection' => [
                'target_runtime' => $targetKind === 'kubernetes' ? 'kubernetes_web' : 'docker_web',
                'target_kind' => $targetKind,
                'site_type' => SiteType::Php,
                'framework' => 'laravel',
                'language' => 'php',
                'confidence' => 'high',
                'document_root' => '/var/www/widget/public',
                'repository_path' => '/var/www/widget',
                'app_port' => null,
                'kubernetes_namespace' => null,
                'reasons' => [],
                'warnings' => [],
                'detected_files' => [],
                'env_template' => ['path' => null, 'keys' => []],
            ],
        ];

        $stub = Mockery::mock(LocalRepositoryInspector::class);
        $stub->shouldReceive('inspect')->andReturn($payload);
        $this->app->instance(LocalRepositoryInspector::class, $stub);
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
