<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Livewire\Servers\WorkspaceSecurityDigestPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.security_digest', fn (): bool => false);
    Feature::define('workspace.security_digest_preview', fn (): bool => true);
    Feature::flushCache();
});

test('security digest preview sidebar shows soon badge when full digest is off', function (): void {
    [$user, $server] = securityDigestPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/security-digest', false);
});

test('security digest route renders coming soon panel when preview active', function (): void {
    [$user, $server] = securityDigestPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.security-digest', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Security digest'))
        ->assertSee(__('fail2ban jails'));
});

test('admin vm servers page lists security digest preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.security_digest_preview')
        ->assertSee(__('Coming soon preview'));
});

test('security digest preview alias redirects to canonical route', function (): void {
    [$user, $server] = securityDigestPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.security-digest-preview', $server))
        ->assertRedirect(route('servers.security-digest', $server));
});

test('security digest preview component redirects when preview active', function (): void {
    [$user, $server] = securityDigestPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSecurityDigestPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.security-digest', $server));
});

test('security digest route is hidden when preview and full digest are off', function (): void {
    Feature::define('workspace.security_digest_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = securityDigestPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.security-digest', $server))
        ->assertNotFound();
});

test('security digest preview respects per-org override', function (): void {
    [$user, $server] = securityDigestPreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.security_digest_preview');

    expect(workspace_security_digest_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.security-digest', $server))
        ->assertNotFound();
});

function securityDigestPreviewUserWithServer(): array
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
