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

test('guest is redirected from infrastructure index', function () {
    $this->get(route('infrastructure.index'))
        ->assertRedirect(route('login'));
});

test('authenticated user can load infrastructure index', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('infrastructure.index'))
        ->assertOk()
        ->assertSee('Infrastructure')
        ->assertSee('Compute');
});

test('infrastructure index shows compute cards and links when cloud enabled', function () {
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('infrastructure.index'));

    $response->assertOk()
        ->assertSee('Servers')
        ->assertSee('Cloud apps')
        ->assertSee('Serverless')
        ->assertSee('Edge')
        ->assertSee('Open cloud apps')
        ->assertSee('Learn more')
        ->assertSee(route('servers.index'), false)
        ->assertSee(route('cloud.index'), false)
        ->assertSee(route('serverless.index'), false)
        ->assertSee(route('edge.index'), false)
        ->assertSee(route('launches.create'), false);
});

test('infrastructure index shows cloud coming soon when surface cloud inactive', function () {
    Feature::define('surface.cloud', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('infrastructure.index'));

    $response->assertOk()
        ->assertSee('Cloud apps')
        ->assertSee('Coming soon')
        ->assertSee('Not available yet')
        ->assertDontSee(route('cloud.index'), false);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
