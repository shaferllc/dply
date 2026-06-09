<?php

declare(strict_types=1);

namespace Tests\Feature\InfrastructureIndexTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

afterEach(function (): void {
    foreach (config('features', []) as $namespace => $flags) {
        foreach ($flags as $leaf => $default) {
            Feature::define("$namespace.$leaf", fn () => (bool) $default);
        }
    }
    Feature::flushCache();
});

test('guest is redirected from infrastructure index', function (): void {
    $this->get(route('infrastructure.index'))
        ->assertRedirect(route('login'));
});

test('infrastructure index redirects to fleet when multi surface and fleet enabled', function (): void {
    Feature::define('surface.cloud', fn () => true);
    Feature::define('surface.edge', fn () => true);
    Feature::define('surface.fleet', fn () => true);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('infrastructure.index'))
        ->assertRedirect(route('fleet.index'));
});

test('infrastructure index shows compute when fleet disabled but multi surface active', function (): void {
    Feature::define('surface.cloud', fn () => true);
    Feature::define('surface.edge', fn () => true);
    Feature::define('surface.fleet', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('infrastructure.index'))
        ->assertOk()
        ->assertSee('Infrastructure')
        ->assertSee('Compute');
});

test('infrastructure index shows edge card when surface edge active', function (): void {
    Feature::define('surface.cloud', fn () => true);
    Feature::define('surface.edge', fn () => true);
    Feature::define('surface.fleet', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('infrastructure.index'));

    $response->assertOk()
        ->assertSee('Edge')
        ->assertSee('Open edge apps')
        ->assertSee(route('edge.index'), false);
});

test('infrastructure index links edge migration when surface edge inactive', function (): void {
    Feature::define('surface.edge', fn () => false);
    Feature::define('surface.cloud', fn () => true);
    Feature::define('surface.fleet', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('infrastructure.index'));

    $response->assertOk()
        ->assertSee('Edge')
        ->assertSee('Coming soon')
        ->assertSee(route('migrate.show', 'vercel'), false)
        ->assertDontSee('Open edge apps');
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
