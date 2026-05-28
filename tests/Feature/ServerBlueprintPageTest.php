<?php

declare(strict_types=1);

namespace Tests\Feature\ServerBlueprintPageTest;

use App\Livewire\Servers\WorkspaceBlueprint;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerBlueprint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.server_blueprint');

function blueprintUserWithServer(): array
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
            'installed_stack' => [
                'database' => 'mysql84',
                'php_version' => '8.4',
                'webserver' => 'nginx',
                'cache_service' => 'redis',
            ],
        ],
    ]);

    return [$user, $org, $server];
}

test('server blueprint page is hidden without feature flag', function (): void {
    Feature::define('workspace.server_blueprint', fn (): bool => false);
    Feature::flushCache();

    [$user, , $server] = blueprintUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.blueprint', $server))
        ->assertStatus(400);
});

test('server blueprint page renders capture form', function (): void {
    [$user, , $server] = blueprintUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.blueprint', $server))
        ->assertOk()
        ->assertSee(__('Save blueprint'))
        ->assertSee(__('Snapshot preview'));
});

test('org can save blueprint from ready server', function (): void {
    [$user, $org, $server] = blueprintUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceBlueprint::class, ['server' => $server])
        ->set('blueprint_name', 'Production golden')
        ->call('saveBlueprint')
        ->assertHasNoErrors();

    expect(ServerBlueprint::query()->where('organization_id', $org->id)->count())->toBe(1);
});
