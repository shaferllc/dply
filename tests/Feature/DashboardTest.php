<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithOrganization(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_dashboard_is_displayed_for_authenticated_user(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Workspace command deck');
        $response->assertSee('Quick actions');
        $response->assertSee('Platform surfaces');
        $response->assertSee('Create your first server');
    }

    public function test_dashboard_prompts_for_provider_setup_when_no_provider_credentials_exist(): void
    {
        $user = $this->userWithOrganization();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Set up a provider');
        $response->assertSee('Add provider credentials before you provision infrastructure.');
    }

    public function test_dashboard_redirects_guest_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login', absolute: false));
    }
}
