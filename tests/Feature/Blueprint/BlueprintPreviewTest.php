<?php

declare(strict_types=1);

namespace Tests\Feature\Blueprint;

use App\Livewire\Servers\WorkspaceBlueprintPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.server_blueprint', fn (): bool => false);
    Feature::define('workspace.server_blueprint_preview', fn (): bool => true);
    Feature::flushCache();
});

function blueprintPreviewUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    return [$user, $server];
}

test('blueprint preview sidebar shows soon badge when full blueprint is off', function (): void {
    [$user, $server] = blueprintPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/blueprint', false);
});

test('blueprint route renders coming soon panel when preview active', function (): void {
    [$user, $server] = blueprintPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.blueprint', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Server blueprints'))
        ->assertSee(__('Capture from server'));
});

test('blueprint preview alias redirects to canonical blueprint route', function (): void {
    [$user, $server] = blueprintPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.blueprint-preview', $server))
        ->assertRedirect(route('servers.blueprint', $server));
});

test('blueprint preview component redirects when preview active', function (): void {
    [$user, $server] = blueprintPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceBlueprintPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.blueprint', $server));
});

test('blueprint preview component is hidden when full blueprint is enabled', function (): void {
    Feature::define('workspace.server_blueprint', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = blueprintPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceBlueprintPreview::class, ['server' => $server])
        ->assertStatus(404);
});

test('blueprint route is hidden when preview and full blueprint are off', function (): void {
    Feature::define('workspace.server_blueprint_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = blueprintPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.blueprint', $server))
        ->assertNotFound();
});
