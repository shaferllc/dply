<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\NginxApacheCacheWorkspaceTest;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ApacheEngineCachePurger;
use App\Services\Servers\NginxEngineCachePurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeWebserverUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

test('nginx cache subtab allows navigation and purge confirm flow', function () {
    $user = makeWebserverUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'nginx'],
    ]);

    $purger = new class extends NginxEngineCachePurger
    {
        public int $calls = 0;

        public function purgeAll(Server $server): void
        {
            $this->calls++;
        }
    };
    app()->instance(NginxEngineCachePurger::class, $purger);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'nginx')
        ->set('engine_subtab', 'cache')
        ->assertSet('engine_subtab', 'cache')
        ->call('openConfirmActionModal', 'purgeNginxEngineCacheConfirmed', [], 'Purge', 'Confirm', 'Purge', true)
        ->call('confirmActionModal')
        ->assertSet('nginx_cache_flash', __('FastCGI and proxy cache storage purged on the server.'));

    expect($purger->calls)->toBe(1);
});

test('apache cache subtab allows navigation and purge confirm flow', function () {
    $user = makeWebserverUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'apache'],
    ]);

    $purger = new class extends ApacheEngineCachePurger
    {
        public int $calls = 0;

        public function purgeAll(Server $server): void
        {
            $this->calls++;
        }
    };
    app()->instance(ApacheEngineCachePurger::class, $purger);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'apache')
        ->set('engine_subtab', 'cache')
        ->assertSet('engine_subtab', 'cache')
        ->call('openConfirmActionModal', 'purgeApacheEngineCacheConfirmed', [], 'Purge', 'Confirm', 'Purge', true)
        ->call('confirmActionModal')
        ->assertSet('apache_cache_flash', __('Apache disk cache storage purged on the server.'));

    expect($purger->calls)->toBe(1);
});
