<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Jobs\RefreshServerInventoryJob;
use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceTools;
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

test('manage tools tab renders compact catalog and runtimes panel', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
        ->assertSee(__(':installed of :total installed', ['installed' => 2, 'total' => 5]))
        ->assertSee(__('Refresh probe'))
        ->assertSee(__('Caches'))
        ->assertSee(__('PHP'))
        ->assertSee(__('Run'))
        ->assertSee(__('Git'))
        ->assertSee(__('Docker Engine'))
        ->assertDontSee(__('Managed runtimes'))
        ->call('setToolsPanel', 'runtimes')
        ->assertSee(__('Managed runtimes'))
        ->assertSee('Bun')
        ->assertSee('Deno')
        ->assertSee('Java')
        ->assertSee(config('server_manage.service_actions.mise_prune.label'));
});

test('manage tools http route renders tools section', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.tools', ['server' => $server]))
        ->assertOk()
        ->assertSee(__('Refresh probe'));
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
        ->test(WorkspaceTools::class, ['server' => $server])
        ->assertSee(__('Updating :action…', ['action' => 'Upgrade Git']));
});

test('manage tools shows loading state while docker install is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:install_docker',
        'label' => 'Install Docker service',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
        ->assertSee(__('Installing :action…', ['action' => 'Install Docker service']));
});

test('manage tools shows loading state while wp-cli install is running', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    ServerManageAction::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'task_name' => 'manage-action:install_wp_cli',
        'label' => 'Install WordPress CLI',
        'status' => ServerManageAction::STATUS_RUNNING,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
        ->assertSee(__('Installing :action…', ['action' => 'Install WordPress CLI']));
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
        ->test(WorkspaceTools::class, ['server' => $server])
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
        ->test(WorkspaceTools::class, ['server' => $server])
        ->set('manageRemoteTaskId', $taskId)
        ->set('manageRemoteTaskName', 'manage-action:repair_git')
        ->assertSee(__('Updating :action…', ['action' => 'Upgrade Git']))
        ->call('pollManageWorkspace')
        ->assertSet('manageRemoteTaskId', null)
        ->assertDontSee(__('Updating :action…', ['action' => 'Upgrade Git']))
        ->assertDontSee(__('Queued…'));

    expect(ServerManageAction::query()->where('server_id', $server->id)->value('status'))
        ->toBe(ServerManageAction::STATUS_FINISHED);
});

test('manage tools git card shows deploy identity form with dply defaults', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
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
        ->test(WorkspaceTools::class, ['server' => $server])
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
        ->test(WorkspaceTools::class, ['server' => $server])
        ->assertSee(__('Running :action…', ['action' => 'Git identity']));
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
        ->test(WorkspaceTools::class, ['server' => $server->fresh()])
        ->assertSee(__('Updating :action…', ['action' => 'Update wp-cli']));
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
        ->test(WorkspaceTools::class, ['server' => $server->fresh()])
        ->assertSee(__('Updating :action…', ['action' => 'Upgrade redis-cli']));
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
        ->test(WorkspaceTools::class, ['server' => $server->fresh()])
        ->assertSee(__('Updating :action…', ['action' => 'Upgrade Docker Engine']));
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
        ->test(WorkspaceTools::class, ['server' => $server])
        ->call('setToolsPanel', 'runtimes')
        ->assertSee(__('Installing :version…', ['version' => '3.3.4']));
});

function manageOverviewUserWithServerWithoutInventory(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => 'test-key',
        'meta' => [],
    ]);

    return [$user, $server->fresh()];
}

test('manage overview auto-dispatches inventory probe when snapshot is missing', function (): void {
    Queue::fake();

    [$user, $server] = manageOverviewUserWithServerWithoutInventory();

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
        ->call('maybeRefreshInventoryProbeOnLoad');

    Queue::assertPushed(RefreshServerInventoryJob::class, fn (RefreshServerInventoryJob $job): bool => $job->serverId === (string) $server->id);
});

test('manage overview skips auto inventory probe when snapshot already exists', function (): void {
    Queue::fake();

    [$user, $server] = manageToolsPageUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
        ->call('maybeRefreshInventoryProbeOnLoad');

    Queue::assertNotPushed(RefreshServerInventoryJob::class);
});

test('manage overview poll dispatches probe once ssh becomes ready', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_PROVISIONING,
        'meta' => [],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
        ->call('pollManageInventoryState');

    Queue::assertNotPushed(RefreshServerInventoryJob::class);

    $server->update([
        'status' => Server::STATUS_READY,
        'ssh_private_key' => 'test-key',
        'ip_address' => '203.0.113.10',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server->fresh()])
        ->call('pollManageInventoryState');

    Queue::assertPushed(RefreshServerInventoryJob::class);
});

test('manage overview shows stale state until inventory arrives then updates on poll', function (): void {
    [$user, $server] = manageOverviewUserWithServerWithoutInventory();

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server])
        ->assertSee(__('Not probed yet'));

    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'inventory_checked_at' => now()->toIso8601String(),
            'manage_units' => ['nginx.service' => ['active_state' => 'active']],
            'inventory_upgradable_packages' => 0,
            'inventory_reboot_required' => false,
            'inventory_extended_snapshot' => "Filesystem      Size  Used Avail Use% Mounted on\n/dev/vda1        20G  4.0G   16G  20% /\n---\n 14:00:01 up 1 day\n---\nMem:           3919        512        3407          0        3407        3407",
        ]),
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceTools::class, ['server' => $server->fresh()])
        ->call('pollManageInventoryState')
        ->assertDontSee(__('Not probed yet'))
        ->assertSee(__('Last probed'));
});
