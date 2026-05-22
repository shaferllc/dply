<?php

declare(strict_types=1);

namespace Tests\Feature\CloudNavLinkTest;
use App\Models\Organization;
use App\Models\User;
use Laravel\Pennant\Feature;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('authenticated dashboard includes cloud link when surface cloud active', function () {
    Feature::define('surface.cloud', fn () => true);
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Cloud sites')
        ->assertSee(route('cloud.index'), escape: false);
});
test('cloud link hidden when surface cloud inactive', function () {
    // Default production state: surface.cloud is OFF.
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()->assertDontSee('Cloud sites');
});
test('unauthenticated root does not show cloud link', function () {
    $response = $this->get('/');

    $response->assertDontSee('Cloud sites');
});
function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
