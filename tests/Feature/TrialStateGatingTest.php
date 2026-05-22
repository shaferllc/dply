<?php

namespace Tests\Feature\TrialStateGatingTest;

use App\Enums\TrialState;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('subscription.standard.trial_days', 14);
    Config::set('subscription.standard.soft_pause_days', 30);
});

test('active trial state when trial in future', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(5)]);

    expect($org->trialState())->toBe(TrialState::ActiveTrial);
    expect($org->canDeploy())->toBeTrue();
    expect($org->canSchedulerRun())->toBeTrue();
});

test('expired soft state within soft window', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);

    expect($org->trialState())->toBe(TrialState::ExpiredSoft);
    expect($org->canDeploy())->toBeFalse();
    expect($org->canSchedulerRun())->toBeFalse();
});

test('expired hard state past soft window', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(31)]);

    expect($org->trialState())->toBe(TrialState::ExpiredHard);
    expect($org->canDeploy())->toBeFalse();
    expect($org->canSchedulerRun())->toBeFalse();
});

test('subscribed state overrides trial window', function () {
    $org = new class extends Organization
    {
        public function onStandardSubscription(): bool
        {
            return true;
        }
    };
    $org->trial_ends_at = now()->subDays(60);

    // hard-expired by date, but subscribed
    expect($org->trialState())->toBe(TrialState::Subscribed);
    expect($org->canDeploy())->toBeTrue();
});

test('no trial state when field is null', function () {
    // The booted-creating hook backfills trial_ends_at on every fresh org,
    // so to simulate a legacy "no trial recorded" row we null it after the
    // fact via direct DB write.
    $org = Organization::factory()->create();
    \DB::table('organizations')->where('id', $org->id)->update(['trial_ends_at' => null]);
    $org->refresh();

    expect($org->trialState())->toBe(TrialState::NoTrial);

    // NoTrial is treated permissively — historical orgs from before
    // the trial-tracking redesign aren't retroactively cut off.
    expect($org->canDeploy())->toBeTrue();
});

test('hard pause starts at returns null for subscribed orgs', function () {
    $org = new class extends Organization
    {
        public function onStandardSubscription(): bool
        {
            return true;
        }
    };
    $org->trial_ends_at = now()->subDays(5);

    expect($org->hardPauseStartsAt())->toBeNull();
});

test('hard pause starts at is trial end plus soft window', function () {
    Config::set('subscription.standard.soft_pause_days', 30);
    $trialEnd = now()->subDays(5);
    $org = Organization::factory()->create(['trial_ends_at' => $trialEnd]);

    $expected = $trialEnd->copy()->addDays(30);
    $actual = $org->hardPauseStartsAt();

    expect($actual)->not->toBeNull();
    expect($actual->timestamp)->toEqualWithDelta($expected->timestamp, 2);
});

test('trial state enum permits billed work only when safe', function () {
    expect(TrialState::ActiveTrial->permitsBilledWork())->toBeTrue();
    expect(TrialState::Subscribed->permitsBilledWork())->toBeTrue();
    expect(TrialState::NoTrial->permitsBilledWork())->toBeTrue();
    expect(TrialState::ExpiredSoft->permitsBilledWork())->toBeFalse();
    expect(TrialState::ExpiredHard->permitsBilledWork())->toBeFalse();
});

test('soft to hard transition at configured boundary', function () {
    Config::set('subscription.standard.soft_pause_days', 30);

    $softOrg = Organization::factory()->create(['trial_ends_at' => now()->subDays(29)]);
    $hardOrg = Organization::factory()->create(['trial_ends_at' => now()->subDays(31)]);

    expect($softOrg->trialState())->toBe(TrialState::ExpiredSoft);
    expect($hardOrg->trialState())->toBe(TrialState::ExpiredHard);
});

test('canceled subscription within grace keeps org subscribed', function () {
    Config::set('subscription.standard.stripe.base_monthly', 'price_grace_base');
    $org = Organization::factory()->create(['trial_ends_at' => null]);

    // Canceled but ends_at in the future = Cashier grace period; valid() true.
    Subscription::factory()
        ->withPrice('price_grace_base')
        ->create([
            'organization_id' => $org->id,
            'stripe_status' => 'canceled',
            'ends_at' => now()->addDays(10),
        ]);

    expect($org->fresh()->trialState())->toBe(TrialState::Subscribed);
});

test('ended subscription within soft window is expired soft', function () {
    Config::set('subscription.standard.soft_pause_days', 30);
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    Subscription::factory()
        ->withPrice('price_x')
        ->create([
            'organization_id' => $org->id,
            'stripe_status' => 'canceled',
            'ends_at' => now()->subDays(5), // grace ended 5 days ago
        ]);

    expect($org->fresh()->trialState())->toBe(TrialState::ExpiredSoft);
    expect($org->fresh()->lapsedFromSubscription())->toBeTrue();
});

test('ended subscription past soft window is expired hard', function () {
    Config::set('subscription.standard.soft_pause_days', 30);
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    Subscription::factory()
        ->withPrice('price_x')
        ->create([
            'organization_id' => $org->id,
            'stripe_status' => 'canceled',
            'ends_at' => now()->subDays(40),
        ]);

    expect($org->fresh()->trialState())->toBe(TrialState::ExpiredHard);
});

test('ended subscription reference beats trial dates', function () {
    // Org had a long-past trial AND a recently-ended subscription.
    // The recent subscription end is what should drive the pause ladder.
    Config::set('subscription.standard.soft_pause_days', 30);
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(400)]);
    Subscription::factory()
        ->withPrice('price_x')
        ->create([
            'organization_id' => $org->id,
            'stripe_status' => 'canceled',
            'ends_at' => now()->subDays(3),
        ]);

    // Without the subscription branch this would be ExpiredHard (trial 400d
    // past). With it, the 3-day-old cancellation keeps it in soft pause.
    expect($org->fresh()->trialState())->toBe(TrialState::ExpiredSoft);
});

test('lapsed from subscription is false for plain trial expiry', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);

    expect($org->trialState())->toBe(TrialState::ExpiredSoft);
    expect($org->lapsedFromSubscription())->toBeFalse();
});
