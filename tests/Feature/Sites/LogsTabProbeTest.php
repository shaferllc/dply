<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\LogsTabProbeTest;

use App\Livewire\Sites\Logs;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The Overview and Sources tabs kept snapping back to Viewer in the browser, so
 * these pin the server-side half of that behaviour: setLogsWorkspaceTab holds
 * the value it is given, and the #[Url(as: 'tab')] binding deep-links straight
 * into a tab. If these keep passing while the browser still reverts, the cause
 * is client-side (wire:navigate history) rather than component state.
 */
function logsTabFixtures(): array
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

test('the workspace tab holds the value it is set to', function (): void {
    [$user, $server, $site] = logsTabFixtures();

    Livewire::actingAs($user)
        ->test(Logs::class, ['server' => $server, 'site' => $site])
        ->assertSet('logsTab', 'viewer')
        ->call('setLogsWorkspaceTab', 'sources')
        ->assertSet('logsTab', 'sources')
        ->call('setLogsWorkspaceTab', 'overview')
        ->assertSet('logsTab', 'overview')
        ->call('setLogsWorkspaceTab', 'alerts')
        ->assertSet('logsTab', 'alerts')
        ->call('setLogsWorkspaceTab', 'notifications')
        ->assertSet('logsTab', 'notifications');
});

test('an unknown tab falls back to the viewer instead of rendering nothing', function (): void {
    [$user, $server, $site] = logsTabFixtures();

    Livewire::actingAs($user)
        ->test(Logs::class, ['server' => $server, 'site' => $site])
        ->call('setLogsWorkspaceTab', 'not-a-tab')
        ->assertSet('logsTab', 'viewer');
});

test('mount honours a tab supplied by the url binding', function (): void {
    [$user, $server, $site] = logsTabFixtures();

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'sources'])
        ->test(Logs::class, ['server' => $server, 'site' => $site])
        ->assertSet('logsTab', 'sources');
});

test('mount honours the notifications tab from the url binding', function (): void {
    [$user, $server, $site] = logsTabFixtures();

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'notifications'])
        ->test(Logs::class, ['server' => $server, 'site' => $site])
        ->assertSet('logsTab', 'notifications')
        ->assertSee(__('Log notifications'));
});
