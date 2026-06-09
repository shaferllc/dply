<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\OlsLscachePurgeTest;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\OpenLiteSpeedLscachePurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeOlsUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

test('purge lscache confirms through modal and surfaces success toast state', function () {
    $user = makeOlsUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'openlitespeed'],
    ]);

    $purger = new class extends OpenLiteSpeedLscachePurger
    {
        public int $calls = 0;

        public function purgeAll(Server $server): void
        {
            $this->calls++;
        }
    };
    $this->app->instance(OpenLiteSpeedLscachePurger::class, $purger);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'openlitespeed')
        ->set('engine_subtab', 'cache')
        ->call('openConfirmActionModal', 'purgeOlsLscacheConfirmed', [], 'Purge LSCache', 'Confirm purge', 'Purge cache', true)
        ->assertSet('confirmActionModalMethod', 'purgeOlsLscacheConfirmed')
        ->call('confirmActionModal')
        ->assertSet('ols_cache_flash', __('LSCache storage purged on the server.'));

    expect($purger->calls)->toBe(1);
});

test('modules subtab is allowed for openlitespeed engine navigation', function () {
    $user = makeOlsUser();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'ssh_private_key' => 'test-key',
        'meta' => ['webserver' => 'openlitespeed'],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->set('workspace_tab', 'openlitespeed')
        ->set('engine_subtab', 'modules')
        ->assertSet('engine_subtab', 'modules');
});
