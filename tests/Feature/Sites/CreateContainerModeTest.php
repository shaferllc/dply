<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CreateContainerModeTest;

use App\Enums\SiteType;
use App\Modules\Launch\Jobs\FinalizeContainerCloudLaunchJob;
use App\Livewire\Sites\Create as SiteCreate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Deploy\LocalRepositoryInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

test('docker host renders container mode form', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->assertSee('Try an open-source preset')
        ->assertSee('Plausible Analytics')
        ->assertSee('Add container')
        ->assertSeeHtml('data-testid="sites-create-container-mode"')
        ->assertDontSee('1. Confirm server context');
});
test('vm host keeps existing form', function () {
    $user = userWithOrganization();
    $server = vmServer($user);

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->assertDontSee('Try an open-source preset')
        ->assertDontSeeHtml('data-testid="sites-create-container-mode"');
});
test('oss preset fills repository url', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->call('applyContainerOssPreset', 'plausible')
        ->assertSet('container_repository_url', 'https://github.com/plausible/analytics.git')
        ->assertSet('container_repository_branch', 'master');
});
test('kubernetes namespace defaults from server meta', function () {
    $user = userWithOrganization();
    $server = kubernetesServer($user, namespace: 'production');

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->assertSet('container_kubernetes_namespace', 'production');
});
test('store container dispatches finalizer job', function () {
    Bus::fake();

    $user = userWithOrganization();
    $server = dockerServer($user);

    stubInspector();

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
    expect(data_get($server->meta, 'container_launch.status'))->toBe('queued');
});
test('store container kubernetes uses per app namespace', function () {
    Bus::fake();

    $user = userWithOrganization();
    $server = kubernetesServer($user, namespace: 'production');

    stubInspector(targetKind: 'kubernetes');

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
});
test('store container redirects kubernetes servers to cluster page', function () {
    // K8s servers' main destination is /cluster (not /overview). The success
    // redirect after a container launch needs to land them there or the
    // launch-progress banner is invisible.
    Bus::fake();

    $user = userWithOrganization();
    $server = kubernetesServer($user);
    stubInspector(targetKind: 'kubernetes');

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->set('container_repo_source', 'manual')
        ->set('container_repository_url', 'https://github.com/org/widget.git')
        ->call('storeContainer')
        ->assertRedirect(route('servers.cluster', $server));
});
test('store container redirects docker servers to overview', function () {
    Bus::fake();

    $user = userWithOrganization();
    $server = dockerServer($user);
    stubInspector();

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->set('container_repo_source', 'manual')
        ->set('container_repository_url', 'https://github.com/org/widget.git')
        ->call('storeContainer')
        ->assertRedirect(route('servers.overview', $server));
});
test('store container validates kubernetes namespace format', function () {
    Bus::fake();

    $user = userWithOrganization();
    $server = kubernetesServer($user);

    stubInspector(targetKind: 'kubernetes');

    Livewire::actingAs($user)
        ->test(SiteCreate::class, ['server' => $server])
        ->set('container_repo_source', 'manual')
        ->set('container_repository_url', 'https://github.com/org/widget.git')
        ->set('container_kubernetes_namespace', 'NOT_VALID')
        ->call('storeContainer')
        ->assertHasErrors('container_kubernetes_namespace');

    Bus::assertNotDispatched(FinalizeContainerCloudLaunchJob::class);
});
function dockerServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
    ]);
}
function vmServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
    ]);
}
function kubernetesServer(User $user, string $namespace = 'default'): Server
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
function stubInspector(string $targetKind = 'docker'): void
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
    app()->instance(LocalRepositoryInspector::class, $stub);
}
function userWithOrganization(): User
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $organization);
    session(['current_organization_id' => $organization->id]);

    return $user;
}
afterEach(function () {
    Mockery::close();
});
