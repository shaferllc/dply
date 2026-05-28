<?php

declare(strict_types=1);

namespace Tests\Feature\ServerReleaseHygienePageTest;

use App\Livewire\Servers\WorkspaceReleaseHygiene;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.release_hygiene', 'workspace.run');

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

function hygieneUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_private_key' => FAKE_SSH_KEY,
        'meta' => [
            'host_kind' => 'vm',
            'release_hygiene_snapshot' => [
                'checked_at' => now()->subHour()->toIso8601String(),
                'sites' => [],
                'system' => [],
            ],
        ],
    ]);

    return [$user, $server];
}

test('server release hygiene page is hidden without feature flag', function (): void {
    Feature::define('workspace.release_hygiene', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = hygieneUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertStatus(400);
});

test('server release hygiene page renders rollup', function (): void {
    [$user, $server] = hygieneUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server))
        ->assertOk()
        ->assertSee(__('Release hygiene'))
        ->assertSee(__('Scan disk'))
        ->assertSee(__('Prune saved command'));
});

test('org owner can install prune saved command once', function (): void {
    [$user, $server] = hygieneUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceReleaseHygiene::class, ['server' => $server])
        ->call('installPruneSavedCommand')
        ->assertHasNoErrors();

    expect(ServerRecipe::query()->where('server_id', $server->id)->count())->toBe(1);

    Livewire::actingAs($user)
        ->test(WorkspaceReleaseHygiene::class, ['server' => $server->fresh()])
        ->call('installPruneSavedCommand')
        ->assertHasNoErrors();

    expect(ServerRecipe::query()->where('server_id', $server->id)->count())->toBe(1);
});

test('non vm host returns 404', function (): void {
    [$user, $server] = hygieneUserWithServer();
    $server->update(['meta' => ['host_kind' => 'kubernetes']]);

    $this->actingAs($user)
        ->get(route('servers.hygiene', $server->fresh()))
        ->assertNotFound();
});
