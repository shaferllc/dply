<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_platform_admin(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login', absolute: false));
    }

    public function test_authenticated_user_can_open_platform_admin_in_testing_environment(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk()
            ->assertSee(__('Platform admin'))
            ->assertSee(__('Runtime & optimization'))
            ->assertSee('included (20)');
    }
}
