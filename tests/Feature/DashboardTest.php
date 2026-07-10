<?php

namespace Tests\Feature\DashboardTest;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('dashboard is displayed for authenticated user', function () {
    $user = userWithOrganization();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Welcome back');
    $response->assertSee('Operate from one place');
    $response->assertSee('Keep the workspace ready');
    $response->assertSee('Add a server');
    $response->assertSee(route('servers.create'), false);
});

test('dashboard prompts for provider setup when no provider credentials exist', function () {
    $user = userWithOrganization();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Add provider credentials before you provision');
    $response->assertSee('Connect a supported infrastructure provider so this workspace can launch and manage real servers instead of stopping at setup.');
});

test('dashboard redirects guest to login', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login', absolute: false));
});
