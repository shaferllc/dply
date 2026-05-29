<?php

declare(strict_types=1);

namespace Tests\Feature\ServerMaintenancePageTest;

use App\Livewire\Servers\WorkspaceMaintenance;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.server_maintenance');

function maintenanceUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    return [$user, $server];
}

test('server maintenance page is hidden when feature and preview are off', function (): void {
    Feature::define('workspace.server_maintenance', fn (): bool => false);
    Feature::define('workspace.server_maintenance_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = maintenanceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertNotFound();
});

test('server maintenance page renders coming soon when feature off but preview on', function (): void {
    Feature::define('workspace.server_maintenance', fn (): bool => false);
    Feature::define('workspace.server_maintenance_preview', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = maintenanceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Maintenance'));
});

test('server maintenance page renders controls', function (): void {
    [$user, $server] = maintenanceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertOk()
        ->assertSee(__('Visitor maintenance'))
        ->assertSee(__('Site impact'))
        ->assertSee(__('Preferred maintenance schedule'))
        ->assertSee(__('Public visitor message'));
});

test('org owner can enable maintenance from livewire', function (): void {
    [$user, $server] = maintenanceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->set('maintenance_message', 'Patch window')
        ->call('enableMaintenance')
        ->assertHasNoErrors();

    expect($server->fresh()->meta['maintenance']['active'] ?? false)->toBeTrue();
});
