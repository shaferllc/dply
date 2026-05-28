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

usesFeatures('workspace.daemon_slo', 'workspace.cert_inventory', 'workspace.deploy_windows');

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

test('daemon slo page renders', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.daemon-slo', $server))
        ->assertOk()
        ->assertSee(__('Worker SLOs'));
});

test('cert inventory page renders', function (): void {
    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.cert-inventory', $server))
        ->assertOk()
        ->assertSee(__('Certificate inventory'));
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

test('feature flags return 400 when disabled', function (): void {
    Feature::define('workspace.daemon_slo', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = vmRoadmapUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.daemon-slo', $server))
        ->assertStatus(400);
});
