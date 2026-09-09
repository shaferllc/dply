<?php

namespace Tests\Feature\HealthReleasesTabByRoleTest;

use App\Livewire\Servers\WorkspaceHealth;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function healthUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function healthServer(string $role): Server
{
    $user = healthUser();

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => ['server_role' => $role],
    ]);
}

test('a database server hides the releases tab', function () {
    $server = healthServer('database');

    Livewire::actingAs($server->user)
        ->test(WorkspaceHealth::class, ['server' => $server])
        ->assertDontSee('health-tab-releases');
});

test('an app server keeps it', function () {
    $server = healthServer('application');

    Livewire::actingAs($server->user)
        ->test(WorkspaceHealth::class, ['server' => $server])
        ->assertSee('health-tab-releases');
});

test('a bookmarked releases tab falls back to overview on a database server', function () {
    $server = healthServer('database');

    // Otherwise the strip renders with no panel under it — the selected tab has
    // nothing left to draw.
    Livewire::withQueryParams(['tab' => 'releases'])
        ->actingAs($server->user)
        ->test(WorkspaceHealth::class, ['server' => $server])
        ->assertSet('healthTab', 'overview');
});

test('the same url is honoured on an app server', function () {
    $server = healthServer('application');

    Livewire::withQueryParams(['tab' => 'releases'])
        ->actingAs($server->user)
        ->test(WorkspaceHealth::class, ['server' => $server])
        ->assertSet('healthTab', 'releases');
});

test('switching to releases is refused on a database server', function () {
    $server = healthServer('database');

    Livewire::actingAs($server->user)
        ->test(WorkspaceHealth::class, ['server' => $server])
        ->call('setHealthWorkspaceTab', 'releases')
        ->assertSet('healthTab', 'overview');
});

test('the skeleton drops the releases tab too', function () {
    $server = healthServer('database');

    // The document GET paints the lazy placeholder; it must not show a tab the
    // hydrated page removes, or the strip visibly reflows.
    $html = test()->actingAs($server->user)->get(route('servers.health', $server))->getContent();

    expect($html)->not->toContain('Releases')
        ->and($html)->not->toContain('Capacity, releases, deploy failures');
});
