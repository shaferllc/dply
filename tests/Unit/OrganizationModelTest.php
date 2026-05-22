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

    public function test_plan_tier_label_defaults_to_trial_without_a_pro_subscription(): void
    {
        $org = new Organization;

        $this->assertSame('Trial', $org->planTierLabel());
    }

    public function test_plan_tier_label_returns_standard_when_org_is_on_standard(): void
    {
        $org = new class extends Organization
        {
            public function onStandardSubscription(): bool
            {
                return true;
            }
        };

        $this->assertSame('Standard', $org->planTierLabel());
    }

    public function test_plan_tier_label_returns_enterprise_when_org_is_on_enterprise(): void
    {
        $org = new class extends Organization
        {
            public function onEnterpriseSubscription(): bool
            {
                return true;
            }
        };

        $this->assertSame('Enterprise', $org->planTierLabel());
    }

    public function test_enterprise_takes_precedence_over_standard(): void
    {
        $org = new class extends Organization
        {
            public function onEnterpriseSubscription(): bool
            {
                return true;
            }

            public function onStandardSubscription(): bool
            {
                return true;
            }
        };

        $this->assertSame('Enterprise', $org->planTierLabel());
    }

    public function test_on_any_paid_plan_is_true_for_each_paid_plan(): void
    {
        $trialOrg = new Organization;
        $this->assertFalse($trialOrg->onAnyPaidPlan());

        $standardOrg = new class extends Organization
        {
            public function onStandardSubscription(): bool
            {
                return true;
            }
        };
        $this->assertTrue($standardOrg->onAnyPaidPlan());

        $enterpriseOrg = new class extends Organization
        {
            public function onEnterpriseSubscription(): bool
            {
                return true;
            }
        };
        $this->assertTrue($enterpriseOrg->onAnyPaidPlan());
    }

    public function test_max_servers_is_unlimited_on_any_paid_plan(): void
    {
        $standardOrg = new class extends Organization
        {
            public function onStandardSubscription(): bool
            {
                return true;
            }
        };

        $this->assertSame(PHP_INT_MAX, $standardOrg->maxServers());
        $this->assertSame(PHP_INT_MAX, $standardOrg->maxSites());
        $this->assertSame('Unlimited', $standardOrg->maxServersDisplay());
        $this->assertSame('Unlimited', $standardOrg->maxSitesDisplay());
    }

    public function test_max_servers_and_sites_are_unlimited_for_all_orgs(): void
    {
        // Under the Standard model there is no server/site cap — trial-state
        // gating (canDeploy / acceptsMetrics) does the abuse-protection work.
        $trialOrg = new Organization;

        $this->assertSame(PHP_INT_MAX, $trialOrg->maxServers());
        $this->assertSame(PHP_INT_MAX, $trialOrg->maxSites());
        $this->assertSame('Unlimited', $trialOrg->maxServersDisplay());
        $this->assertSame('Unlimited', $trialOrg->maxSitesDisplay());
    }

    public function test_org_creation_starts_a_14_day_trial(): void
    {
        config(['subscription.standard.trial_days' => 14]);
        $org = Organization::factory()->create();

        $this->assertNotNull($org->trial_ends_at);
        $this->assertTrue($org->trial_ends_at->isFuture());
        $this->assertEqualsWithDelta(14 * 86400, now()->diffInSeconds($org->trial_ends_at, false), 5);
    }

    public function test_org_creation_respects_explicitly_set_trial(): void
    {
        $explicit = now()->addDays(30);
        $org = Organization::factory()->create(['trial_ends_at' => $explicit]);

        $this->assertEqualsWithDelta($explicit->timestamp, $org->trial_ends_at->timestamp, 2);
    }

    public function test_on_dply_trial_is_true_while_trial_is_future(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addDay()]);

        $this->assertTrue($org->onDplyTrial());
    }

    public function test_on_dply_trial_is_false_after_trial_expires(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);

        $this->assertFalse($org->onDplyTrial());
    }
}
