<?php

declare(strict_types=1);

namespace Tests\Feature\Hygiene;

use App\Livewire\Servers\WorkspaceReleaseHygienePreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.release_hygiene', fn (): bool => false);
    Feature::define('workspace.release_hygiene_preview', fn (): bool => true);
    Feature::flushCache();
});

test('hygiene preview sidebar shows soon badge when full hygiene is off', function (): void {
    [$user, $server] = hygienePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/hygiene', false);
});

test('hygiene route renders coming soon panel when preview active', function (): void {
    [$user, $server] = hygienePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Release hygiene'))
        ->assertSee(__('Disk headroom'));
});

test('admin vm servers page lists hygiene preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.release_hygiene_preview')
        ->assertSee(__('Coming soon preview'));
});

test('hygiene preview alias redirects to canonical hygiene route', function (): void {
    [$user, $server] = hygienePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene-preview', $server))
        ->assertRedirect(route('servers.hygiene', $server));
});

test('hygiene preview component redirects when preview active', function (): void {
    [$user, $server] = hygienePreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceReleaseHygienePreview::class, ['server' => $server])
        ->assertRedirect(route('servers.hygiene', $server));
});

test('hygiene route is hidden when preview and full hygiene are off', function (): void {
    Feature::define('workspace.release_hygiene_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = hygienePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertNotFound();
});

test('hygiene preview respects per-org override', function (): void {
    [$user, $server] = hygienePreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.release_hygiene_preview');

    expect(workspace_release_hygiene_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertNotFound();
});

function hygienePreviewUserWithServer(): array
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
