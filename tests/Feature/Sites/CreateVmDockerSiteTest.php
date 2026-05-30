<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\CreateVmDockerSiteTest;

use App\Jobs\ProvisionSiteJob;
use App\Livewire\Sites\Create as SitesCreate;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithVmDockerServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => [
            'webserver' => 'nginx',
            'manage_docker' => ['present' => true, 'version' => '29.5.2'],
            'php_inventory' => [
                'supported' => true,
                'installed_versions' => ['8.4'],
                'detected_default_version' => '8.4',
            ],
            'php_new_site_default_version' => '8.4',
        ],
    ]);

    return [$user, $server];
}

test('create page preselects docker deploy stack from query string', function () {
    [$user, $server] = userWithVmDockerServer();

    Livewire::actingAs($user)
        ->withQueryParams(['deploy_stack' => 'docker'])
        ->test(SitesCreate::class, ['server' => $server])
        ->assertSet('form.deploy_stack', 'docker');
});

test('store creates vm docker site metadata without pre-allocating internal port', function () {
    Queue::fake();

    [$user, $server] = userWithVmDockerServer();

    Livewire::actingAs($user)
        ->test(SitesCreate::class, ['server' => $server])
        ->set('form.deploy_stack', 'docker')
        ->set('form.name', 'Docker App')
        ->set('form.primary_hostname', 'docker-app.example.test')
        ->set('form.type', 'node')
        ->set('form.app_port', 3000)
        ->set('form.git_repository_url', 'https://github.com/example/app.git')
        ->call('store')
        ->assertHasNoErrors()
        ->assertRedirect();

    $site = Site::query()->where('name', 'Docker App')->first();

    expect($site)->not->toBeNull()
        ->and($site->runtimeProfile())->toBe('docker_web')
        ->and($site->runtimeTargetFamily())->toBe('byo_vm_docker')
        ->and($site->usesVmDockerRuntime())->toBeTrue()
        ->and($site->internal_port)->toBeNull()
        ->and(data_get($site->meta, 'runtime_target.vm_docker'))->toBeTrue()
        ->and(data_get($site->meta, 'docker_runtime.app_type'))->toBe('node');

    Queue::assertPushed(ProvisionSiteJob::class);
});
