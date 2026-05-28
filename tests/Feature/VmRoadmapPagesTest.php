<?php

declare(strict_types=1);

namespace Tests\Feature\VmRoadmapPagesTest;

use App\Livewire\Servers\WorkspaceDeployPolicy;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerDeployPolicyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.daemon_slo', 'workspace.cert_inventory', 'workspace.deploy_windows', 'workspace.ssh_access_graph', 'workspace.ssh_sessions', 'workspace.server_cost', 'workspace.security_digest');

const FAKE_SSH_KEY = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n";

function vmRoadmapUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'ssh_private_key' => FAKE_SSH_KEY,
        'meta' => ['host_kind' => 'vm'],
    ]);

    return [$user, $server];
}

test('daemon slo route redirects to daemons workspace', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.daemon-slo', $server))
        ->assertRedirect(route('servers.daemons', $server));
});

test('daemons workspace shows worker health when daemon slo feature is on', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.daemons', $server))
        ->assertOk()
        ->assertSee(__('Worker health'))
        ->assertSee(__('Refresh status'));
});

test('daemons workspace hides worker health when daemon slo feature is off', function (): void {
    Feature::define('workspace.daemon_slo', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.daemons', $server))
        ->assertOk()
        ->assertDontSee(__('Worker health'))
        ->assertSee(__('Programs at a glance'));
});

test('cert inventory page renders', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.cert-inventory', $server))
        ->assertOk()
        ->assertSee(__('Certificates'))
        ->assertSee(__('Certificate stats'))
        ->assertSee(__('All certificates'));
});

test('deploy policy page saves weekend freeze', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDeployPolicy::class, ['server' => $server])
        ->set('policy_enabled', true)
        ->call('applyWeekendFreezePreset')
        ->call('savePolicy')
        ->assertHasNoErrors();

    $policy = app(ServerDeployPolicyGuard::class)->policyForServer($server->fresh());
    expect($policy['enabled'])->toBeTrue()
        ->and($policy['deny_rules'])->not->toBeEmpty();
});

test('cert inventory feature flag returns 400 when disabled', function (): void {
    Feature::define('workspace.cert_inventory', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.cert-inventory', $server))
        ->assertStatus(400);
});

test('ssh access graph page renders', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.ssh-access', $server))
        ->assertOk()
        ->assertSee(__('SSH access'));
});

test('security digest page renders', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.security-digest', $server))
        ->assertOk()
        ->assertSee(__('Security digest'))
        ->assertSee(__('Overall'))
        ->assertSee(__('Refresh digest'));
});

test('server cost page renders', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $server->update(['meta' => ['host_kind' => 'vm', 'cost_monthly_note' => '$5/mo']]);

    $this->actingAs($user)
        ->get(route('servers.cost', $server))
        ->assertOk()
        ->assertSee(__('Cost'))
        ->assertSee(__('Overall'));
});
