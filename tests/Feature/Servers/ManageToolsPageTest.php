<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceManage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerManageAction;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Models\User;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function manageToolsPageUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'test-key',
        'meta' => [
            'manage_tools' => [
                'mise' => ['present' => true, 'version' => '2024.1.0'],
                'git' => ['present' => true, 'version' => 'git version 2.43.0'],
                'docker' => ['present' => false, 'version' => null],
            ],
            'manage_mise_runtimes' => ['node' => ['versions' => ['20.16.0'], 'active' => '20.16.0']],
            'manage_system_runtimes' => [],
            'inventory_checked_at' => now()->toIso8601String(),
        ],
    ]);

    $run = ServerProvisionRun::create([
        'server_id' => $server->id,
        'attempt' => 1,
        'status' => 'completed',
    ]);
    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack_summary',
        'label' => 'stack summary',
        'metadata' => ['expected_services' => ['nginx', 'php-fpm'], 'php_version' => '8.3'],
    ]);
    ServerInstalledServices::flushCaches();

    return [$user, $server->fresh()];
}

test('manage tools tab renders expanded toolchain overview', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->assertSee(__('Server toolchain'))
        ->assertDontSee(__('Tool catalog'))
        ->assertSee(__('Open Caches'))
        ->assertSee(__('Open PHP'))
        ->assertSee(__('Open Run'))
        ->assertSee(__('Managed runtimes'))
        ->assertSee(__('Git'))
        ->assertSee(__('Docker Engine'))
        ->assertSee('Bun')
        ->assertSee('Deno')
        ->assertSee('Java')
        ->assertSee(__('Prune unused runtimes'));
});

test('manage tools http route renders tools section', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.manage', ['server' => $server, 'section' => 'tools']))
        ->assertOk()
        ->assertSee(__('Server toolchain'));
});

test('manage tools shows loading state while git upgrade is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:repair_git',
        'label' => 'Upgrade Git',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->assertSee(__('Running on server…'));
});

test('manage tools ignores finished remote task cache for git busy state', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    $taskId = (string) Str::uuid();
    Cache::put(ServerManageRemoteSshJob::cacheKey($taskId), [
        'status' => 'finished',
        'output' => 'Installed: git version 2.43.0',
        'error' => null,
        'flash_success' => null,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->set('manageRemoteTaskId', $taskId)
        ->set('manageRemoteTaskName', 'manage-action:repair_git')
        ->assertDontSee(__('Running on server…'))
        ->assertDontSee(__('Queued…'));
});

test('manage tools clears git upgrade loading after remote task finishes', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    $taskId = (string) Str::uuid();
    Cache::put(ServerManageRemoteSshJob::cacheKey($taskId), [
        'status' => 'finished',
        'output' => 'Installed: git version 2.43.0',
        'error' => null,
        'flash_success' => 'Upgrade Git finished.',
    ]);

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:repair_git',
        'label' => 'Upgrade Git',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->set('manageRemoteTaskId', $taskId)
        ->set('manageRemoteTaskName', 'manage-action:repair_git')
        ->assertSee(__('Running on server…'))
        ->call('pollManageWorkspace')
        ->assertSet('manageRemoteTaskId', null)
        ->assertDontSee(__('Running on server…'))
        ->assertDontSee(__('Queued…'));

    expect(ServerManageAction::query()->where('server_id', $server->id)->value('status'))
        ->toBe(ServerManageAction::STATUS_FINISHED);
});

test('manage tools git card shows deploy identity form with dply defaults', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->assertSee(__('Deploy user identity'))
        ->assertSee(__('Save identity'))
        ->assertSee(__('Use Dply default'))
        ->assertSet('git_deploy_identity_email', 'deploy+'.$server->id.'@dply.host');
});

test('manage tools save deploy git identity dispatches remote job', function (): void {
    config(['server_manage.queue_remote_tasks' => true]);
    Queue::fake();

    [$user, $server] = manageToolsPageUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->set('git_deploy_identity_name', 'Acme Deploy')
        ->set('git_deploy_identity_email', 'deploy+acme@dply.host')
        ->call('saveDeployGitIdentity')
        ->assertSet('manageRemoteTaskId', fn ($id) => is_string($id) && strlen($id) > 0);

    Queue::assertPushed(ServerManageRemoteSshJob::class);
});

test('manage tools shows loading state while git identity save is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:set_deploy_git_identity',
        'label' => 'Git identity',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->assertSee(__('Running on server…'));
});

test('manage tools shows loading state while wp-cli update is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();
    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'manage_tools' => array_merge($server->meta['manage_tools'] ?? [], [
                'wp_cli' => ['present' => true, 'version' => 'WP-CLI 2.10.0'],
            ]),
        ]),
    ]);

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:update_wp_cli',
        'label' => 'Update wp-cli',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server->fresh(), 'section' => 'tools'])
        ->assertSee(__('Running on server…'));
});

test('manage tools shows loading state while redis-cli upgrade is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'redis',
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
        'status' => ServerCacheService::STATUS_RUNNING,
        'port' => 6379,
    ]);

    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'manage_tools' => array_merge($server->meta['manage_tools'] ?? [], [
                'redis_cli' => ['present' => true, 'version' => 'redis-cli 7.0.15'],
            ]),
        ]),
    ]);

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:repair_redis_cli',
        'label' => 'Upgrade redis-cli',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server->fresh(), 'section' => 'tools'])
        ->assertSee(__('Running on server…'));
});

test('manage tools shows loading state while docker upgrade is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();
    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'manage_tools' => array_merge($server->meta['manage_tools'] ?? [], [
                'docker' => ['present' => true, 'version' => 'Docker version 27.0.0'],
            ]),
        ]),
    ]);

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:repair_docker',
        'label' => 'Upgrade Docker Engine',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server->fresh(), 'section' => 'tools'])
        ->assertSee(__('Running on server…'));
});

test('manage tools shows loading state while mise runtime install is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'mise-runtime:install:ruby@3.3.4',
        'label' => 'Installing ruby 3.3.4',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceManage::class, ['server' => $server, 'section' => 'tools'])
        ->assertSee(__('Installing :version…', ['version' => '3.3.4']))
        ->assertSee(__('Running on server…'));
});
