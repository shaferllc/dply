<?php

namespace Tests\Feature;

use App\Enums\TrialState;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TrialStateGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscription.standard.trial_days', 14);
        Config::set('subscription.standard.soft_pause_days', 30);
    }

    public function test_active_trial_state_when_trial_in_future(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(5)]);

        $this->assertSame(TrialState::ActiveTrial, $org->trialState());
        $this->assertTrue($org->canDeploy());
        $this->assertTrue($org->canSchedulerRun());
    }

    public function test_expired_soft_state_within_soft_window(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);

        $this->assertSame(TrialState::ExpiredSoft, $org->trialState());
        $this->assertFalse($org->canDeploy());
        $this->assertFalse($org->canSchedulerRun());
    }

    public function test_expired_hard_state_past_soft_window(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(31)]);

        $this->assertSame(TrialState::ExpiredHard, $org->trialState());
        $this->assertFalse($org->canDeploy());
        $this->assertFalse($org->canSchedulerRun());
    }

    public function test_subscribed_state_overrides_trial_window(): void
    {
        $org = new class extends Organization
        {
            public function onStandardSubscription(): bool
            {
                return true;
            }
        };
        $org->trial_ends_at = now()->subDays(60); // hard-expired by date, but subscribed

        $this->assertSame(TrialState::Subscribed, $org->trialState());
        $this->assertTrue($org->canDeploy());
    }

    public function test_no_trial_state_when_field_is_null(): void
    {
        // The booted-creating hook backfills trial_ends_at on every fresh org,
        // so to simulate a legacy "no trial recorded" row we null it after the
        // fact via direct DB write.
        $org = Organization::factory()->create();
        \DB::table('organizations')->where('id', $org->id)->update(['trial_ends_at' => null]);
        $org->refresh();

        $this->assertSame(TrialState::NoTrial, $org->trialState());
        // NoTrial is treated permissively — historical orgs from before
        // the trial-tracking redesign aren't retroactively cut off.
        $this->assertTrue($org->canDeploy());
    }

    public function test_hard_pause_starts_at_returns_null_for_subscribed_orgs(): void
    {
        $org = new class extends Organization
        {
            public function onStandardSubscription(): bool
            {
                return true;
            }
        };
        $org->trial_ends_at = now()->subDays(5);

        $this->assertNull($org->hardPauseStartsAt());
    }

    public function test_hard_pause_starts_at_is_trial_end_plus_soft_window(): void
    {
        Config::set('subscription.standard.soft_pause_days', 30);
        $trialEnd = now()->subDays(5);
        $org = Organization::factory()->create(['trial_ends_at' => $trialEnd]);

        $expected = $trialEnd->copy()->addDays(30);
        $actual = $org->hardPauseStartsAt();

        $this->assertNotNull($actual);
        $this->assertEqualsWithDelta($expected->timestamp, $actual->timestamp, 2);
    }

    public function test_trial_state_enum_permits_billed_work_only_when_safe(): void
    {
        $this->assertTrue(TrialState::ActiveTrial->permitsBilledWork());
        $this->assertTrue(TrialState::Subscribed->permitsBilledWork());
        $this->assertTrue(TrialState::NoTrial->permitsBilledWork());
        $this->assertFalse(TrialState::ExpiredSoft->permitsBilledWork());
        $this->assertFalse(TrialState::ExpiredHard->permitsBilledWork());
    }

    public function test_soft_to_hard_transition_at_configured_boundary(): void
    {
        Config::set('subscription.standard.soft_pause_days', 30);

        $softOrg = Organization::factory()->create(['trial_ends_at' => now()->subDays(29)]);
        $hardOrg = Organization::factory()->create(['trial_ends_at' => now()->subDays(31)]);

        $this->assertSame(TrialState::ExpiredSoft, $softOrg->trialState());
        $this->assertSame(TrialState::ExpiredHard, $hardOrg->trialState());
    }
}
