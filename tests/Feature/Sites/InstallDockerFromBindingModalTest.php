<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\InstallDockerFromBindingModalTest;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Sites\ResourceMap;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function bindingModalSite(array $serverMeta = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'fake-key',
        'meta' => $serverMeta,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}

test('database placement offers in-place docker install when the engine is missing', function () {
    [$user, $server, $site] = bindingModalSite(['webserver' => 'nginx']);

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->assertSee(__('Docker container on this server'))
        ->assertSee(__('Install Docker'))
        ->assertDontSee(__('Install Docker from Server → Manage → Tools first.'));
});

test('installing docker from the binding modal queues the manage action and stays on the page', function () {
    Queue::fake();
    [$user, $server, $site] = bindingModalSite(['webserver' => 'nginx']);

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->call('confirmInstallDockerOnServer')
        ->assertSet('showConfirmActionModal', true)
        ->call('confirmActionModal')
        ->assertSet('showConfirmActionModal', false)
        ->assertNotSet('dockerInstallRunId', null);

    expect($component->get('dockerInstallRunId'))->not->toBeEmpty();

    Queue::assertPushed(ServerManageRemoteSshJob::class, function (ServerManageRemoteSshJob $job) use ($server): bool {
        return $job->serverId === $server->id
            && $job->taskName === 'manage-action:install_docker'
            && $job->consoleActionId !== null;
    });

    expect(ServerManageAction::query()
        ->where('server_id', $server->id)
        ->where('task_name', 'manage-action:install_docker')
        ->where('status', ServerManageAction::STATUS_QUEUED)
        ->exists())->toBeTrue();
});

test('docker placement unlocks after the in-page install finishes', function () {
    [$user, $server, $site] = bindingModalSite(['webserver' => 'nginx']);

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:install_docker',
        'label' => 'Install Docker service',
        'status' => ServerManageAction::STATUS_FINISHED,
    ]);

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->set('dockerInstallRunId', 'run-from-this-page')
        ->call('syncDockerInstallProgress')
        ->assertSet('bindingForm.placement', 'docker')
        ->assertSet('dockerInstallRunId', null);

    expect($server->fresh()->dockerEnginePresent())->toBeTrue();
});
