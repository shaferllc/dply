<?php

declare(strict_types=1);

namespace Tests\Feature\StandbyBlueprintWizardTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

usesFeatures('launch.standby_blueprint');

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('standby blueprint wizard is hidden without feature flag', function (): void {
    Feature::define('launch.standby_blueprint', fn (): bool => false);
    Feature::flushCache();

    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('launches.standby'))
        ->assertStatus(400);
});

test('standby blueprint wizard renders catalog', function (): void {
    $user = userWithOrganization();

    $this->actingAs($user)
        ->get(route('launches.standby'))
        ->assertOk()
        ->assertSee(__('Standby blueprints'))
        ->assertSee(__('Edge hybrid origin failover'));
});

test('standby blueprint playbook renders steps for byo site', function (): void {
    $user = userWithOrganization();
    $org = Organization::query()->first();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Worker',
    ]);

    $this->actingAs($user)
        ->get(route('launches.standby', ['blueprint' => 'byo_standby_server']))
        ->assertOk()
        ->assertSee(__('Playbook steps'))
        ->assertSee('Worker');
});
