<?php

namespace Tests\Unit\Services\Billing\RealtimeTierBillingTest;

use App\Services\Billing\DesiredBillingState;

const PLAN = ['key' => 'starter', 'label' => 'Starter', 'price_cents' => 1500, 'max_servers' => 3];

// Mirrors config/realtime.php so the assertions read against known prices.
beforeEach(function () {
    config()->set('realtime.tiers', [
        'starter' => ['label' => 'Starter', 'max_connections' => 5000, 'price_cents' => 1500],
        'growth' => ['label' => 'Growth', 'max_connections' => 25000, 'price_cents' => 4900],
        'scale' => ['label' => 'Scale', 'max_connections' => 100000, 'price_cents' => 14900],
    ]);
    config()->set('realtime.default_tier', 'starter');
});

test('a single starter realtime app adds its tier price to the bill', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PLAN,
        realtimeTierQuantities: ['starter' => 1],
    );

    expect($state->realtimeCount)->toBe(1);
    expect($state->realtimeSubtotalCents)->toBe(1500);
    // Plan price (1500) + realtime (1500).
    expect($state->monthlyTotalCents)->toBe(3000);
});

test('mixed-tier realtime apps sum each tier price', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PLAN,
        realtimeTierQuantities: ['starter' => 2, 'growth' => 1, 'scale' => 1],
    );

    expect($state->realtimeCount)->toBe(4);
    // 2*1500 + 1*4900 + 1*14900 = 22800
    expect($state->realtimeSubtotalCents)->toBe(22800);
    expect($state->realtimeTierQuantities)->toBe(['starter' => 2, 'growth' => 1, 'scale' => 1]);
});

test('moving an app from starter to growth raises the realtime subtotal', function () {
    $before = DesiredBillingState::fromPlanAndUsage(plan: PLAN, realtimeTierQuantities: ['starter' => 1]);
    $after = DesiredBillingState::fromPlanAndUsage(plan: PLAN, realtimeTierQuantities: ['growth' => 1]);

    expect($after->realtimeSubtotalCents - $before->realtimeSubtotalCents)->toBe(3400);
});

test('legacy flat realtime inputs still bill when no tier quantities are given', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PLAN,
        realtimeCount: 2,
        realtimeUnitCents: 900,
    );

    expect($state->realtimeCount)->toBe(2);
    expect($state->realtimeSubtotalCents)->toBe(1800);
    // Flat fallback attributes the count to the default tier for the line map.
    expect($state->realtimeTierQuantities)->toBe(['starter' => 2]);
});

test('no realtime apps bills nothing for realtime', function () {
    $state = DesiredBillingState::fromPlanAndUsage(plan: PLAN);

    expect($state->realtimeCount)->toBe(0);
    expect($state->realtimeSubtotalCents)->toBe(0);
    expect($state->monthlyTotalCents)->toBe(1500);
});

test('the snapshot array exposes the per-tier realtime quantities', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PLAN,
        realtimeTierQuantities: ['growth' => 3],
    );

    $array = $state->toArray();

    expect($array['realtime_count'])->toBe(3);
    expect($array['realtime_subtotal_cents'])->toBe(14700);
    expect($array['realtime_tier_quantities'])->toBe(['growth' => 3]);
});
