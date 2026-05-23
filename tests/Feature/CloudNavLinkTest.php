<?php

declare(strict_types=1);

namespace Tests\Feature\CloudNavLinkTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

test('authenticated dashboard includes cloud apps link when surface cloud active', function () {
    Feature::define('surface.cloud', fn () => true);
    Feature::flushCache();
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Cloud apps')
        ->assertSee(route('cloud.index'), escape: false);
});

test('cloud apps link hidden when surface cloud inactive', function () {
    Feature::define('surface.cloud', fn () => false);
    Feature::flushCache();

    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertDontSee('Cloud apps')
        ->assertDontSee(route('cloud.index'), false);
});

test('browse dropdown includes compute apps and org sections', function () {
    Feature::define('surface.cloud', fn () => true);
    Feature::flushCache();
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Compute')
        ->assertSee('Apps')
        ->assertSee('Org')
        ->assertSee('Servers')
        ->assertSee('Serverless')
        ->assertSee('Edge')
        ->assertSee('Sites')
        ->assertSee('Organizations')
        ->assertSee(route('servers.index'), false)
        ->assertSee(route('serverless.index'), false)
        ->assertSee(route('edge.index'), false)
        ->assertSee(route('sites.index'), false)
        ->assertSee(route('organizations.index'), false);
});

test('unauthenticated root does not show cloud apps link', function () {
    $response = $this->get('/');

    $response->assertDontSee('Cloud apps');
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
