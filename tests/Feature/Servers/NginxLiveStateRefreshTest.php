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

test('nginx certs live state renders the real panel, not the coming-soon teaser', function () {
    $user = makeUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'nginx'],
    ]);

    // Certs came off $nginxComingSoonSubtabs once the probe's units['certs']
    // (ssl_certificate paths + openssl expiry) was wired to the live-state
    // table. It stays read-only — issuance lives in the Certificates module —
    // so the panel signposts there instead of teasing an unbuilt feature.
    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'nginx')
        ->set('engine_subtab', 'certs')
        ->assertSee(__('TLS certificates'))
        ->assertSee(__('Cert inventory'))
        ->assertDontSee(__('nginx certs preview'));
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
