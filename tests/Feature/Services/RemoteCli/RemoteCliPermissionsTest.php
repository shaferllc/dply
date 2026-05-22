<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RemoteCli;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use App\Services\RemoteCli\RemoteCliPermissions;
use App\Services\RemoteCli\RiskLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pure permission-gate matrix. Q17:
 *
 *   role    | Read | MutatingRecoverable | Destructive
 *   --------|------|---------------------|-------------
 *   owner   |  ✓   |          ✓          |     ✓
 *   admin   |  ✓   |          ✓          |     ✓
 *   member  |  ✓   |          ✓          |     ✗
 *   none    |  ✗   |          ✗          |     ✗
 *   system  |  ✓   |          ✓          |     ✓        (user === null bypass)
 */
class RemoteCliPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithRole(?string $role): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        if ($role !== null) {
            $org->users()->attach($user->id, ['role' => $role]);
        }
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        return [$user, $site];
    }

    public function test_owner_can_run_anything(): void
    {
        [$user, $site] = $this->makeUserWithRole('owner');
        $gate = new RemoteCliPermissions;

        $this->assertTrue($gate->can($user, $site, RiskLevel::Read));
        $this->assertTrue($gate->can($user, $site, RiskLevel::MutatingRecoverable));
        $this->assertTrue($gate->can($user, $site, RiskLevel::Destructive));
    }

    public function test_admin_can_run_anything(): void
    {
        [$user, $site] = $this->makeUserWithRole('admin');
        $gate = new RemoteCliPermissions;

        $this->assertTrue($gate->can($user, $site, RiskLevel::Read));
        $this->assertTrue($gate->can($user, $site, RiskLevel::MutatingRecoverable));
        $this->assertTrue($gate->can($user, $site, RiskLevel::Destructive));
    }

    public function test_member_can_read_and_recoverable_but_not_destructive(): void
    {
        [$user, $site] = $this->makeUserWithRole('member');
        $gate = new RemoteCliPermissions;

        $this->assertTrue($gate->can($user, $site, RiskLevel::Read));
        $this->assertTrue($gate->can($user, $site, RiskLevel::MutatingRecoverable));
        $this->assertFalse($gate->can($user, $site, RiskLevel::Destructive));
    }

    public function test_non_member_can_run_nothing(): void
    {
        [$user, $site] = $this->makeUserWithRole(role: null);
        $gate = new RemoteCliPermissions;

        $this->assertFalse($gate->can($user, $site, RiskLevel::Read));
        $this->assertFalse($gate->can($user, $site, RiskLevel::MutatingRecoverable));
        $this->assertFalse($gate->can($user, $site, RiskLevel::Destructive));
    }

    public function test_system_run_with_no_user_bypasses_gate(): void
    {
        [, $site] = $this->makeUserWithRole('member');
        $gate = new RemoteCliPermissions;

        $this->assertTrue($gate->can(null, $site, RiskLevel::Read));
        $this->assertTrue($gate->can(null, $site, RiskLevel::MutatingRecoverable));
        $this->assertTrue($gate->can(null, $site, RiskLevel::Destructive));
    }

    public function test_ensure_can_throws_with_command_in_message(): void
    {
        [$user, $site] = $this->makeUserWithRole('member');
        $gate = new RemoteCliPermissions;

        try {
            $gate->ensureCan($user, $site, RiskLevel::Destructive, 'db drop');
            $this->fail('Expected RemoteCliPermissionDeniedException');
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->assertSame(RiskLevel::Destructive, $e->risk);
            $this->assertSame('db drop', $e->command);
            $this->assertStringContainsString('db drop', $e->getMessage());
        }
    }
}
