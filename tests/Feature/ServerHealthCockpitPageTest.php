<?php

declare(strict_types=1);

namespace Tests\Feature\ServerHealthCockpitPageTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

usesFeatures('workspace.health');

function healthUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['cpu_pct' => 30, 'mem_pct' => 40, 'disk_pct' => 50],
    ]);

    return [$user, $server];
}

test('server health page is hidden without feature flag', function (): void {
    Feature::define('workspace.health', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = healthUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.health', $server))
        ->assertStatus(400);
});

test('server health page renders cockpit', function (): void {
    [$user, $server] = healthUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.health', $server))
        ->assertOk()
        ->assertSee(__('Guest metrics snapshot'))
        ->assertSee(__('Overall'));
});
