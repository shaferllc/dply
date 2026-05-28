<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Livewire\Servers\WorkspaceManage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Models\User;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->assertSee(__('Docker Engine'));
});

test('manage tools http route renders tools section', function (): void {
    [$user, $server] = manageToolsPageUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.manage', ['server' => $server, 'section' => 'tools']))
        ->assertOk()
        ->assertSee(__('Server toolchain'));
});
