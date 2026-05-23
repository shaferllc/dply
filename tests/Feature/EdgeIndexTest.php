<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeIndexTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

test('guest is redirected from edge index', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();

    $this->get(route('edge.index'))
        ->assertRedirect(route('login'));
});

test('shows coming soon when surface edge inactive', function () {
    Feature::define('surface.edge', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertSee('Not available yet')
        ->assertDontSee('No edge sites found');
});

test('authenticated user sees edge sites index when surface edge active', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertOk()
        ->assertSee('Edge sites')
        ->assertSee('No edge sites found');
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
