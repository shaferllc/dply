<?php

declare(strict_types=1);

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\LiveState\EngineLiveState;
use App\Services\Servers\LiveState\NginxLiveStateProbe;
use App\Services\Servers\NginxModulesConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function nginxModulesUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

test('nginx modules sub-tab lists dynamic modules from service', function () {
    $user = nginxModulesUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'nginx'],
    ]);

    $stub = Mockery::mock(NginxModulesConfig::class);
    $stub->shouldReceive('read')
        ->once()
        ->andReturn([
            'modules' => [
                [
                    'name' => 'mod-http-geoip',
                    'conf_file' => 'mod-http-geoip',
                    'enabled' => false,
                    'protected' => false,
                    'type' => 'geo',
                    'source' => 'dynamic',
                    'package' => 'libnginx-mod-http-geoip',
                    'installed' => false,
                    'so_path' => '',
                ],
            ],
            'builtins' => [['name' => 'http-ssl', 'type' => 'builtin']],
            'supports_dynamic' => true,
            'unreadable' => false,
        ]);
    app()->instance(NginxModulesConfig::class, $stub);

    $probeStub = new class extends NginxLiveStateProbe
    {
        protected function runFreshProbe(Server $server): EngineLiveState
        {
            return new EngineLiveState(
                engine: 'nginx',
                capturedAt: CarbonImmutable::now(),
                isFresh: true,
                units: ['hosts' => [], 'upstreams' => [], 'certs' => [], 'modules' => [], 'workers' => []],
            );
        }
    };
    app()->instance(NginxLiveStateProbe::class, $probeStub);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('setWorkspaceTab', 'nginx')
        ->call('setEngineSubtab', 'modules')
        ->call('loadActiveEngineSubtabData')
        ->assertSee(__('nginx dynamic modules'))
        ->assertSee('mod-http-geoip')
        ->assertSee(__('Install & enable'));
});
