<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceDocker;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\SshConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeRemoteShell;

uses(RefreshDatabase::class);

usesFeatures('workspace.docker');

function dockerWorkspaceUserWithServer(array $meta = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'test-key',
        'meta' => array_merge([
            'manage_docker' => [
                'present' => true,
                'version' => 'Docker version 27.0.0',
                'containers_running' => 2,
                'containers_stopped' => 1,
                'images_count' => 4,
            ],
            'inventory_checked_at' => now()->toIso8601String(),
        ], $meta),
    ]);

    return [$user, $server];
}

test('docker workspace overview renders probe summary', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->assertSee(__('Docker'))
        ->assertSee('Docker version 27.0.0')
        ->assertSee(__('Running containers'))
        ->assertSee('2');
});

test('docker workspace http route renders', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.docker', $server))
        ->assertOk()
        ->assertSee(__('Engine'));
});

test('docker workspace shows ops not ready without ssh', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();
    $server->update(['ssh_private_key' => null]);

    $this->actingAs($user)
        ->get(route('servers.docker', $server))
        ->assertOk()
        ->assertSee(__('Provisioning and SSH must be ready before you can use this section.'));
});

test('docker workspace loads containers over ssh', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')
            ->once()
            ->andReturn(ProcessOutput::make("abc123\tweb\tnginx:alpine\tUp 2 hours\trunning\n"));
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('setWorkspaceTab', 'containers')
        ->assertSet('workspace_tab', 'containers')
        ->assertSee('web')
        ->assertSee('nginx:alpine');
});

test('docker workspace container start dispatches remote job', function (): void {
    config(['server_manage.queue_remote_tasks' => true]);
    Queue::fake();

    [$user, $server] = dockerWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('runAllowlistedManageAction', 'docker_container_start', 'abc123')
        ->assertSet('manageRemoteTaskId', fn ($id) => is_string($id) && strlen($id) > 0);

    Queue::assertPushed(ServerManageRemoteSshJob::class);
});

test('docker workspace loads volumes tab over ssh', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')
            ->once()
            ->andReturn(ProcessOutput::make("data_vol\tlocal\tlocal\n"));
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('setWorkspaceTab', 'volumes')
        ->assertSee('data_vol');
});

test('docker workspace pull image rejects empty input', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->set('pullImageInput', '')
        ->call('confirmDockerImagePull')
        ->assertSet('manageRemoteTaskId', null);
});

test('docker workspace opens container logs modal', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')
            ->once()
            ->andReturn(ProcessOutput::make("line one\nline two\n"));
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('openContainerLogs', 'abc123', 'web')
        ->assertSet('logsModalContainerId', 'abc123')
        ->assertSee('line one');
});

test('docker workspace maintenance tab shows system df', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')
            ->once()
            ->andReturn(ProcessOutput::make("Images\t4\t2\t1.2GB\t800MB\n"));
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('setWorkspaceTab', 'maintenance')
        ->assertSee('Images')
        ->assertSee('1.2GB');
});

test('docker workspace container exec validates command before confirm', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('openContainerExec', 'abc123', 'web')
        ->set('execModalCommand', '')
        ->call('submitContainerExec')
        ->assertSet('execModalContainerId', 'abc123');

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('openContainerExec', 'abc123', 'web')
        ->set('execModalCommand', 'php artisan migrate')
        ->call('submitContainerExec')
        ->assertSet('execModalContainerId', null);
});

test('docker workspace compose up dispatches remote job', function (): void {
    config(['server_manage.queue_remote_tasks' => true]);
    Queue::fake();

    [$user, $server] = dockerWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('runAllowlistedManageAction', 'docker_compose_up', null, null, null, 'my-app', '/srv/my-app/docker-compose.dply.yml')
        ->assertSet('manageRemoteTaskId', fn ($id) => is_string($id) && strlen($id) > 0);

    Queue::assertPushed(ServerManageRemoteSshJob::class);
});

test('docker workspace opens compose logs modal', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
        $mock->shouldReceive('runInlineBash')
            ->andReturn(ProcessOutput::make("web-1 | started\n"));
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('openComposeLogs', 'my-app', '/srv/my-app/docker-compose.dply.yml')
        ->assertSet('composeLogsModalProject', 'my-app')
        ->assertSet('composeLogsModalError', null)
        ->assertSet('composeLogsModalContent', fn ($value) => str_contains((string) $value, 'web-1'));
});

test('docker workspace container shell runs commands and keeps history', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    $shell = new FakeRemoteShell(function (string $command): ?string {
        if (str_contains($command, 'docker exec') && str_contains($command, 'pwd')) {
            return "/app\n";
        }

        return null;
    });

    $this->mock(SshConnectionFactory::class, function ($mock) use ($shell): void {
        $mock->shouldReceive('forServer')->andReturn($shell);
    });

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('openContainerShell', 'abc123', 'web')
        ->assertSet('shellModalContainerId', 'abc123')
        ->set('shellModalCommand', 'pwd')
        ->call('runContainerShellCommand')
        ->assertSet('shellModalHistory', fn (array $history): bool => count($history) === 1
            && ($history[0]['cmd'] ?? '') === 'pwd'
            && str_contains((string) ($history[0]['out'] ?? ''), '/app'));
});

test('docker workspace container shell opens with empty history', function (): void {
    [$user, $server] = dockerWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->call('openContainerShell', 'abc123', 'web')
        ->assertSet('shellModalContainerId', 'abc123')
        ->assertSet('shellModalContainerName', 'web')
        ->assertSet('shellModalHistory', []);
});
