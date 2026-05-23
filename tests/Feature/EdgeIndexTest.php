<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeIndexTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest is redirected from edge index', function () {
    $this->get(route('edge.index'))
        ->assertRedirect(route('login'));
});

test('authenticated user can load edge coming soon index', function () {
    $user = ownerWithOrg();

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertOk()
        ->assertSee('Edge')
        ->assertSee('Coming soon')
        ->assertSee('JavaScript frameworks')
        ->assertSee(route('infrastructure.index'), false);
});

function ownerWithOrg(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}
