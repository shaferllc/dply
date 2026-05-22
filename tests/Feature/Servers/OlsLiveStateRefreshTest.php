<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\OlsLiveStateRefreshTest;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\LiveState\EngineLiveState;
use App\Services\Servers\LiveState\OlsLiveStateProbe;
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
test('refresh engine live state invokes probe and caches result', function () {
    $user = makeUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => ['webserver' => 'openlitespeed'],
    ]);

    // Stub probe — short-circuits the SSH path. Writes a tiny state into
    // Server.meta so the assertion below can verify the cache hand-off.
    $stub = new class extends OlsLiveStateProbe
    {
        public function runFreshProbe(Server $server): EngineLiveState
        {
            $this->calls++;

            return new EngineLiveState(
                engine: 'openlitespeed',
                capturedAt: CarbonImmutable::parse('2026-05-12T18:00:00Z'),
                isFresh: true,
                units: ['vhosts' => [['name' => 'demo-site']]],
            );
        }
    };
    $this->app->instance(OlsLiveStateProbe::class, $stub);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'openlitespeed')
        ->call('refreshEngineLiveState');

    expect($stub->calls)->toBe(1);

    $server->refresh();
    $cached = data_get($server->meta, 'webserver_live_state.openlitespeed');
    expect($cached)->not->toBeNull();
    expect($cached['engine'])->toBe('openlitespeed');
    expect($cached['units']['vhosts'][0]['name'])->toBe('demo-site');
});
test('engine live state round trips through meta', function () {
    // The DTO's fromArray / toArray pair must be lossless for the keys
    // we serialize. Direct DTO test (no Livewire involvement).
    $state = new EngineLiveState(
        engine: 'openlitespeed',
        capturedAt: CarbonImmutable::parse('2026-05-12T18:00:00Z'),
        isFresh: true,
        units: ['vhosts' => [['name' => 'a']]],
        engineSpecific: ['errors' => []],
    );

    $rehydrated = EngineLiveState::fromArray($state->toArray());

    expect($rehydrated)->not->toBeNull();
    expect($rehydrated->engine)->toBe('openlitespeed');
    expect($rehydrated->capturedAt->equalTo($state->capturedAt))->toBeTrue();
    expect($rehydrated->units['vhosts'])->toBe([['name' => 'a']]);
});
