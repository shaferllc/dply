<?php

declare(strict_types=1);

use App\Jobs\RunServerConfigOpJob;
use App\Livewire\Servers\WorkspaceConfiguration;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function configurationWorkspaceUserWithServer(): array
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
            'webserver' => 'nginx',
            'inventory_checked_at' => now()->toIso8601String(),
        ],
    ]);

    return [$user, $server];
}

test('configuration workspace route renders editor shell', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.configuration', $server))
        ->assertOk()
        ->assertSee(__('Configuration editor'));
});

test('configuration workspace defers catalog discovery until wire init', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceConfiguration::class, ['server' => $server])
        ->assertSet('configCatalogLoaded', false)
        ->assertSet('groupedConfigFiles', [])
        ->call('loadConfigCatalog')
        ->assertSet('configCatalogLoaded', true);
});

test('manage configuration section redirects to configuration workspace', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.manage', ['server' => $server, 'section' => 'configuration']))
        ->assertRedirect(route('servers.configuration', $server));
});

test('webserver config subtab redirects to scoped configuration workspace', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'nginx', 'sub' => 'config'])
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->assertRedirect(route('servers.configuration', ['server' => $server, 'scope' => 'nginx']));
});

test('load config file queues read job', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Queue::fake();

    Livewire::actingAs($user)
        ->test(WorkspaceConfiguration::class, ['server' => $server])
        ->call('loadConfigFile', '/etc/ssh/sshd_config')
        ->assertSet('pending_load_path', '/etc/ssh/sshd_config')
        ->assertSet('config_selected_path', '/etc/ssh/sshd_config')
        ->assertViewHas('configConsoleRun', null);

    Queue::assertPushed(RunServerConfigOpJob::class, function (RunServerConfigOpJob $job) use ($server): bool {
        return $job->serverId === $server->id
            && $job->op === 'read'
            && $job->path === '/etc/ssh/sshd_config'
            && $job->engine === null;
    });
});

test('disallowed config path is rejected', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceConfiguration::class, ['server' => $server])
        ->call('loadConfigFile', '/etc/passwd')
        ->assertSet('pending_load_path', null);
});

test('save opens diff confirm modal before queueing write', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Queue::fake();

    Livewire::actingAs($user)
        ->test(WorkspaceConfiguration::class, ['server' => $server])
        ->set('config_selected_path', '/etc/ssh/sshd_config')
        ->set('config_contents', "Port 2222\n")
        ->set('config_original_contents', "Port 22\n")
        ->call('saveConfigFile')
        ->assertSet('configSaveConfirmOpen', true);

    Queue::assertNothingPushed();
});

test('confirm save queues write job with revision recording', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Queue::fake();

    Livewire::actingAs($user)
        ->test(WorkspaceConfiguration::class, ['server' => $server])
        ->set('config_selected_path', '/etc/ssh/sshd_config')
        ->set('config_contents', "Port 2222\n")
        ->set('config_original_contents', "Port 22\n")
        ->set('configSaveConfirmOpen', true)
        ->call('confirmConfigSave');

    Queue::assertPushed(RunServerConfigOpJob::class, function (RunServerConfigOpJob $job) use ($server): bool {
        return $job->serverId === $server->id
            && $job->op === 'write'
            && $job->path === '/etc/ssh/sshd_config'
            && $job->recordRevision === true;
    });
});

test('validate config buffer queues validate job', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Queue::fake();

    Livewire::actingAs($user)
        ->test(WorkspaceConfiguration::class, ['server' => $server])
        ->set('config_selected_path', '/etc/ssh/sshd_config')
        ->set('config_contents', "Port 22\n")
        ->call('validateConfigBuffer');

    Queue::assertPushed(RunServerConfigOpJob::class, fn (RunServerConfigOpJob $job): bool => $job->op === 'validate');
});

test('per-path draft stash preserves buffer when switching files', function (): void {
    [$user, $server] = configurationWorkspaceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceConfiguration::class, ['server' => $server])
        ->set('config_selected_path', '/etc/ssh/sshd_config')
        ->set('config_contents', "draft-a\n")
        ->set('config_drafts', [])
        ->call('loadConfigFile', '/etc/redis/redis.conf')
        ->assertSet('config_drafts./etc/ssh/sshd_config', "draft-a\n");
});
