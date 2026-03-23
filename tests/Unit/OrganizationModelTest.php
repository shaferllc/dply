<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_member_returns_true_for_attached_user(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $this->assertTrue($org->hasMember($user));
    }

    public function test_has_member_returns_false_for_non_attached_user(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $this->assertFalse($org->hasMember($user));
    }

    public function test_has_admin_access_returns_true_for_owner(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->assertTrue($org->hasAdminAccess($user));
    }

    public function test_has_admin_access_returns_true_for_admin(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);

        $this->assertTrue($org->hasAdminAccess($user));
    }

    public function test_has_admin_access_returns_false_for_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $this->assertFalse($org->hasAdminAccess($user));
    }
}
