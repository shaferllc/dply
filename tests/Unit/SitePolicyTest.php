<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\User;
use App\Policies\SitePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected SitePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SitePolicy;
    }

    public function test_create_denies_without_current_organization(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->currentOrganization());

        $this->assertFalse($this->policy->create($user));
    }

    public function test_create_allows_when_org_under_limit(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $this->assertTrue($this->policy->create($user));
    }

    public function test_create_denies_deployer(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);
        session(['current_organization_id' => $org->id]);

        $this->assertFalse($this->policy->create($user));
    }
}
