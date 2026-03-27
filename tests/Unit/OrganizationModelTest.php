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

    public function test_wants_deploy_email_notifications_defaults_true(): void
    {
        $org = Organization::factory()->create();

        $this->assertTrue($org->fresh()->wantsDeployEmailNotifications());
    }

    public function test_wants_deploy_email_notifications_respects_disabled_column(): void
    {
        $org = Organization::factory()->create(['deploy_email_notifications_enabled' => false]);

        $this->assertFalse($org->wantsDeployEmailNotifications());
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

    public function test_user_is_deployer(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);

        $this->assertTrue($org->userIsDeployer($user));
        $this->assertTrue($org->hasMember($user));
        $this->assertFalse($org->hasAdminAccess($user));
    }
}
