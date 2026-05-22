<?php


namespace Tests\Feature\DashboardTest;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
    $response->assertSee('Workspace command deck');
    $response->assertSee('Quick actions');
    $response->assertSee('Platform surfaces');
    $response->assertSee('Open launchpad');
    $response->assertSee(route('launches.create'), false);
});

test('dashboard prompts for provider setup when no provider credentials exist', function () {
    $user = userWithOrganization();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Set up a provider');
    $response->assertSee('Add provider credentials before you provision infrastructure.');
});

test('dashboard redirects guest to login', function () {
    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('login', absolute: false));
});