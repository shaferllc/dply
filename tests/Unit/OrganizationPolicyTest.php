<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected OrganizationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new OrganizationPolicy;
    }

    public function test_view_allows_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $this->assertTrue($this->policy->view($user, $org));
    }

    public function test_view_denies_non_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $this->assertFalse($this->policy->view($user, $org));
    }

    public function test_update_allows_admin(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);

        $this->assertTrue($this->policy->update($user, $org));
    }

    public function test_update_denies_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $this->assertFalse($this->policy->update($user, $org));
    }

    public function test_delete_allows_only_owner(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->assertTrue($this->policy->delete($user, $org));
    }

    public function test_delete_denies_admin(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);

        $this->assertFalse($this->policy->delete($user, $org));
    }
}
