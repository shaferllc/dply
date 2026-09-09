<?php

declare(strict_types=1);

namespace Tests\Feature\CloudNavLinkTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('browse dropdown includes compute and org sections', function () {
    $user = ownerWithOrg();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Servers')
        ->assertSee('Sites')
        ->assertSee('Organizations')
        ->assertSee(route('servers.index'), false)
        ->assertSee(route('sites.index'), false)
        ->assertSee(route('organizations.index'), false)
        ->assertDontSee('Serverless');
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
