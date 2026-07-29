<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\NginxLiveStateRefreshTest;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\LiveState\EngineLiveState;
use App\Services\Servers\LiveState\NginxLiveStateProbe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

test('nginx certs live state shows coming soon preview', function () {
    $user = makeUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'nginx'],
    ]);

    // nginx live-state sub-tabs (hosts/upstreams/certs/…) still render the
    // shared coming-soon teaser instead of the probe-backed empty state.
    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'nginx')
        ->set('engine_subtab', 'certs')
        ->assertSee(__('nginx certificates'))
        ->assertSee(__('nginx certs preview'))
        ->assertDontSee(__('In sync — nothing to list'));
});

test('nginx live state cache is reused within ttl', function () {
    $user = makeUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'nginx'],
    ]);

    $stub = new class extends NginxLiveStateProbe
    {
        public int $calls = 0;

        protected function runFreshProbe(Server $server): EngineLiveState
        {
            $this->calls++;

            return new EngineLiveState(
                engine: 'nginx',
                capturedAt: CarbonImmutable::now(),
                isFresh: true,
                units: ['certs' => []],
            );
        }
    };
    $this->app->instance(NginxLiveStateProbe::class, $stub);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'nginx')
        ->call('setEngineSubtab', 'certs')
        ->call('loadActiveEngineSubtabData');

    expect($stub->calls)->toBe(1);

    $component->call('setEngineSubtab', 'hosts')
        ->call('loadActiveEngineSubtabData')
        ->call('setEngineSubtab', 'certs')
        ->call('loadActiveEngineSubtabData');

    expect($stub->calls)->toBe(1);
});
