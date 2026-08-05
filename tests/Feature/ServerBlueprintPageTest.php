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

test('server blueprint page is hidden without feature flag or preview', function (): void {
    Feature::define('workspace.server_blueprint', fn (): bool => false);
    Feature::define('workspace.server_blueprint_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, , $server] = blueprintUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.blueprint', $server))
        ->assertNotFound();
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

test('clicking a blueprint name opens its snapshot details', function (): void {
    [$user, $org, $server] = blueprintUserWithServer();

    $blueprint = ServerBlueprint::query()->create([
        'organization_id' => $org->id,
        'source_server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'golden web',
        'snapshot' => [
            'version' => 1,
            'stack' => ['webserver' => 'nginx', 'php_version' => '8.4', 'database' => 'mysql84', 'cache_service' => 'redis'],
            'server_role' => 'application',
            'install_profile' => 'laravel',
            'runtime_defaults' => ['node' => '22'],
            'firewall_rules' => [
                ['name' => 'HTTPS', 'port' => '443', 'protocol' => 'tcp', 'source' => 'any', 'action' => 'allow', 'enabled' => true],
            ],
            'supervisor_programs' => [
                ['slug' => 'horizon', 'program_type' => 'horizon', 'command' => 'php artisan horizon', 'user' => 'dply', 'numprocs' => 1],
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBlueprint::class, ['server' => $server])
        // The name is a button, not static text.
        ->assertSee('golden web')
        ->call('openDetailModal', $blueprint->id)
        ->assertSet('viewingBlueprintId', $blueprint->id)
        // The detail the table can't carry: what rides along with the stack.
        ->assertSee(__('Firewall rules'))
        ->assertSee('HTTPS')
        ->assertSee(__('Daemons'))
        ->assertSee('horizon')
        ->assertSee('Node 22')
        ->call('closeDetailModal')
        ->assertSet('viewingBlueprintId', '');
});

test('a blueprint from another organization is not viewable', function (): void {
    [$user, , $server] = blueprintUserWithServer();

    $otherOrg = Organization::factory()->create();
    $foreign = ServerBlueprint::query()->create([
        'organization_id' => $otherOrg->id,
        'created_by_user_id' => $user->id,
        'name' => 'someone elses blueprint',
        'snapshot' => ['version' => 1, 'stack' => []],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceBlueprint::class, ['server' => $server])
        ->call('openDetailModal', $foreign->id)
        // Scoped to the current org, so the id resolves to nothing.
        ->assertDontSee('someone elses blueprint');
});
