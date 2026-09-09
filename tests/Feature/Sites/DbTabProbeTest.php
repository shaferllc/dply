<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteDatabaseDeferredCapabilitiesTest;

use App\Livewire\Sites\Database;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Engine capabilities are an SSH probe. CLAUDE.md forbids SSH on the
 * render/HTTP path (30s max_execution_time), and running it there made the page
 * 504 on a cold cache and every tab click hang for the whole round-trip.
 *
 * mount() and the first render must therefore stay off the wire; the probe only
 * runs once wire:init calls loadDatabaseCapabilities().
 */
function deferredCapabilitiesFixtures(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => 'vm', 'webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}

test('first render does not probe capabilities', function (): void {
    [$user, $server, $site] = deferredCapabilitiesFixtures();

    Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site])
        ->assertSet('capabilitiesLoaded', false)
        // No engine is assumed before the probe, so the panel shows its skeleton
        // rather than a wrong "no engine installed" empty state.
        ->assertSet('new_db_engine', '');
});

test('tab switching works without waiting on the probe', function (): void {
    [$user, $server, $site] = deferredCapabilitiesFixtures();

    Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server, 'site' => $site])
        ->assertSet('dbTab', 'databases')
        ->call('setDatabaseTab', 'create')
        ->assertSet('dbTab', 'create')
        ->call('setDatabaseTab', 'notifications')
        ->assertSet('dbTab', 'notifications')
        ->assertSet('capabilitiesLoaded', false);
});

test('an unreachable host still resolves the panel instead of hanging', function (): void {
    // No SSH key, so forServer() short-circuits to defaults — the page must land
    // on "loaded, nothing installed", never sit in the skeleton forever.
    [$user, $server, $site] = deferredCapabilitiesFixtures();
    $server->forceFill(['ssh_private_key' => null])->save();

    Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server->fresh(), 'site' => $site])
        ->call('loadDatabaseCapabilities')
        ->assertSet('capabilitiesLoaded', true);
});

test('loading twice does not re-probe', function (): void {
    [$user, $server, $site] = deferredCapabilitiesFixtures();
    $server->forceFill(['ssh_private_key' => null])->save();

    Livewire::actingAs($user)
        ->test(Database::class, ['server' => $server->fresh(), 'site' => $site])
        ->call('loadDatabaseCapabilities')
        ->assertSet('capabilitiesLoaded', true)
        ->call('loadDatabaseCapabilities')
        ->assertSet('capabilitiesLoaded', true);
});
