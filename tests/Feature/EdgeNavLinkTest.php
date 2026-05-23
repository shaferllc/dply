<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeNavLinkTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

test('authenticated dashboard includes edge link when surface edge active', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Edge')
        ->assertSee(route('edge.index'), false);
});

test('edge link visible when surface edge inactive', function () {
    Feature::define('surface.edge', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Edge')
        ->assertSee(route('edge.index'), false);
});

test('browse dropdown includes edge when surface edge active', function () {
    Feature::define('surface.edge', fn () => true);
    Feature::flushCache();
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Compute')
        ->assertSee('Edge')
        ->assertSee(route('edge.index'), false);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
