<?php

declare(strict_types=1);

namespace Tests\Feature\ServerPatchAdvisorPageTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

usesFeatures('workspace.patch_advisor');

function patchAdvisorUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => 'vm',
            'inventory_checked_at' => now()->subHour()->toIso8601String(),
            'inventory_upgradable_packages' => 1,
            'inventory_reboot_required' => false,
            'inventory_os_pretty' => 'Ubuntu 24.04.2 LTS',
        ],
    ]);

    return [$user, $server];
}

test('server patch advisor page is hidden without feature flag', function (): void {
    Feature::define('workspace.patch_advisor', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = patchAdvisorUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.patches', $server))
        ->assertStatus(400);
});

test('server patch advisor page renders rollup', function (): void {
    [$user, $server] = patchAdvisorUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.patches', $server))
        ->assertOk()
        ->assertSee(__('Patches'))
        ->assertSee(__('Apt actions'))
        ->assertSee(__('Refresh scan'))
        ->assertSee('Ubuntu 24.04.2 LTS');
});

test('manage updates section redirects to patches when patch advisor is enabled', function (): void {
    [$user, $server] = patchAdvisorUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.manage', ['server' => $server, 'section' => 'updates']))
        ->assertRedirect(route('servers.patches', $server));
});

test('settings inventory section redirects to patches when patch advisor is enabled', function (): void {
    [$user, $server] = patchAdvisorUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.settings', ['server' => $server, 'section' => 'inventory']))
        ->assertRedirect(route('servers.patches', $server));
});

test('non vm host returns 404', function (): void {
    [$user, $server] = patchAdvisorUserWithServer();
    $server->update(['meta' => ['host_kind' => 'kubernetes']]);

    $this->actingAs($user)
        ->get(route('servers.patches', $server->fresh()))
        ->assertNotFound();
});
