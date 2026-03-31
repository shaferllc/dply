<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillOrganizationMembershipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_workspace_for_users_without_an_organization(): void
    {
        $user = User::factory()->create([
            'name' => 'Orphaned User',
            'email' => 'orphaned@example.com',
        ]);

        $this->assertFalse($user->organizations()->exists());

        Artisan::call('dply:backfill-organizations');

        $org = Organization::query()->where('name', "Orphaned User's Workspace")->first();

        $this->assertNotNull($org);
        $this->assertTrue($org->hasMember($user->fresh()));
    }
}
