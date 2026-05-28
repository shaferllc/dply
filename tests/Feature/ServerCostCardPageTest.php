<?php

declare(strict_types=1);

namespace Tests\Feature\ServerCostCardPageTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

usesFeatures('workspace.server_cost');

function costCardUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm', 'cost_monthly_note' => '$8/mo'],
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['cpu_pct' => 20, 'mem_pct' => 25],
    ]);

    return [$user, $server];
}

test('legacy server cost route redirects to settings governance', function (): void {
    [$user, $server] = costCardUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.cost', $server))
        ->assertRedirect(route('servers.settings', ['server' => $server, 'section' => 'governance']).'#settings-cost-estimate');
});

test('server cost page is hidden without feature flag', function (): void {
    Feature::define('workspace.server_cost', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = costCardUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.cost', $server))
        ->assertStatus(400);
});

test('settings governance tab renders stack estimate', function (): void {
    [$user, $server] = costCardUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.settings', ['server' => $server, 'section' => 'governance']))
        ->assertOk()
        ->assertSee(__('Stack estimate'))
        ->assertSee(__('Full stack'))
        ->assertSee(__('Cost & lifecycle'));
});
