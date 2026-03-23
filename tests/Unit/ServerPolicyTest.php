<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Policies\ServerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected ServerPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ServerPolicy;
    }

    public function test_view_allows_owner(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($this->policy->view($user, $server));
    }

    public function test_view_allows_organization_member(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
        ]);

        $this->assertTrue($this->policy->view($user, $server));
    }

    public function test_view_denies_non_member(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($otherUser->id, ['role' => 'owner']);
        $server = Server::factory()->create([
            'user_id' => $otherUser->id,
            'organization_id' => $org->id,
        ]);

        $this->assertFalse($this->policy->view($user, $server));
    }

    public function test_create_denies_without_current_organization(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->currentOrganization());

        $this->assertFalse($this->policy->create($user));
    }

    public function test_create_allows_with_current_organization(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        session(['current_organization_id' => $org->id]);

        $this->assertTrue($this->policy->create($user));
    }
}
