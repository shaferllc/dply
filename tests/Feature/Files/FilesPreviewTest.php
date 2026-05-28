<?php

declare(strict_types=1);

namespace Tests\Feature\Files;

use App\Livewire\Servers\WorkspaceFilesPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.files', fn (): bool => false);
    Feature::define('workspace.files_preview', fn (): bool => true);
    Feature::flushCache();
});

function filesPreviewUserWithServer(): array
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

test('files preview sidebar shows soon badge when full files is off', function (): void {
    [$user, $server] = filesPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/files', false);
});

test('files route renders coming soon panel when preview active', function (): void {
    [$user, $server] = filesPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.files', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Remote files'))
        ->assertSee(__('SSH directory browser'));
});

test('files preview alias redirects to canonical files route', function (): void {
    [$user, $server] = filesPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.files-preview', $server))
        ->assertRedirect(route('servers.files', $server));
});

test('files preview component redirects when preview active', function (): void {
    [$user, $server] = filesPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceFilesPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.files', $server));
});

test('files preview component is hidden when full files is enabled', function (): void {
    Feature::define('workspace.files', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = filesPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceFilesPreview::class, ['server' => $server])
        ->assertStatus(404);
});

test('files route is hidden when preview and full files are off', function (): void {
    Feature::define('workspace.files_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = filesPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.files', $server))
        ->assertNotFound();
});

test('deployer can view files coming soon preview', function (): void {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    $this->actingAs($user)
        ->get(route('servers.files', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'));
});
