<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\LiveState\EngineLiveState;
use App\Services\Servers\LiveState\OlsLiveStateProbe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Refresh-now action on the OLS engine sub-tabs. We substitute the probe
 * with a stub that records the call and writes a fixed EngineLiveState
 * back into Server.meta — proves the wiring (auth, container binding,
 * cache write-through) without needing an actual SSH session.
 */
class OlsLiveStateRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);
        session(['current_organization_id' => $org->id]);

        return $user->fresh();
    }

    public function test_refresh_engine_live_state_invokes_probe_and_caches_result(): void
    {
        $user = $this->makeUser();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'meta' => ['webserver' => 'openlitespeed'],
        ]);

        // Stub probe — short-circuits the SSH path. Writes a tiny state into
        // Server.meta so the assertion below can verify the cache hand-off.
        $stub = new class extends OlsLiveStateProbe
        {
            public int $calls = 0;

            protected function runFreshProbe(Server $server): EngineLiveState
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

        $this->assertSame(1, $stub->calls);

        $server->refresh();
        $cached = data_get($server->meta, 'webserver_live_state.openlitespeed');
        $this->assertNotNull($cached);
        $this->assertSame('openlitespeed', $cached['engine']);
        $this->assertSame('demo-site', $cached['units']['vhosts'][0]['name']);
    }

    public function test_refresh_no_op_for_engine_without_probe(): void
    {
        $user = $this->makeUser();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $user->currentOrganization()->id,
            'meta' => ['webserver' => 'nginx'],
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->set('workspace_tab', 'nginx')
            ->call('refreshEngineLiveState');

        $server->refresh();
        $this->assertNull(data_get($server->meta, 'webserver_live_state.nginx'));
    }

    public function test_engine_live_state_round_trips_through_meta(): void
    {
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

        $this->assertNotNull($rehydrated);
        $this->assertSame('openlitespeed', $rehydrated->engine);
        $this->assertTrue($rehydrated->capturedAt->equalTo($state->capturedAt));
        $this->assertSame([['name' => 'a']], $rehydrated->units['vhosts']);
    }
}
