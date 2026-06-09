<?php

namespace Tests\Unit\Services\Billing\DesiredBillingStateTest;

use App\Enums\ServerTier;
use App\Services\Billing\DesiredBillingState;

const FREE = ['key' => 'free', 'label' => 'Free', 'price_cents' => 0, 'max_servers' => 1];

const STARTER = ['key' => 'starter', 'label' => 'Starter', 'price_cents' => 900, 'max_servers' => 3];

const PRO = ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 1900, 'max_servers' => 10];

const BUSINESS = ['key' => 'business', 'label' => 'Business', 'price_cents' => 3900, 'max_servers' => null];

test('free plan with no managed products bills nothing', function () {
    $state = DesiredBillingState::fromPlanAndUsage(plan: FREE);

    expect($state->planKey)->toBe('free');
    expect($state->planPriceCents)->toBe(0);
    expect($state->serverCount())->toBe(0);
    expect($state->monthlyTotalCents)->toBe(0);
    expect($state->isFree())->toBeTrue();
});

test('a paid plan price is the whole server fee regardless of size', function () {
    // One XL server on the Starter plan still costs the flat Starter price —
    // size no longer affects the dply fee.
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: STARTER,
        tierQuantities: ['xl' => 1],
    );

    expect($state->planPriceCents)->toBe(900);
    expect($state->serverSubtotalCents)->toBe(900);
    expect($state->serverCount())->toBe(1);
    expect($state->monthlyTotalCents)->toBe(900);
    expect($state->isFree())->toBeFalse();
});

test('mixed fleet keeps a display breakdown but bills the flat plan', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PRO,
        tierQuantities: ['m' => 2, 'l' => 1, 'xs' => 1],
    );

    expect($state->serverCount())->toBe(4);
    expect($state->tierQuantities)->toBe(['xs' => 1, 's' => 0, 'm' => 2, 'l' => 1, 'xl' => 0]);
    expect($state->planPriceCents)->toBe(1900);
    expect($state->monthlyTotalCents)->toBe(1900);
});

test('business plan covers an unlimited fleet at a flat price', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: BUSINESS,
        tierQuantities: ['xl' => 25],
    );

    expect($state->serverCount())->toBe(25);
    expect($state->planPriceCents)->toBe(3900);
    expect($state->monthlyTotalCents)->toBe(3900);
});

test('unknown tier keys are ignored in the breakdown', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: STARTER,
        tierQuantities: ['xs' => 1, 'mythical_tier' => 99],
    );

    expect($state->serverCount())->toBe(1);
    expect($state->tierQuantities)->toBe(['xs' => 1, 's' => 0, 'm' => 0, 'l' => 0, 'xl' => 0]);
});

test('negative quantities are clamped to zero', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        tierQuantities: ['xs' => -5],
    );

    expect($state->serverCount())->toBe(0);
});

test('quantity for returns zero for unbought tiers', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PRO,
        tierQuantities: ['m' => 3],
    );

    expect($state->quantityFor(ServerTier::M))->toBe(3);
    expect($state->quantityFor(ServerTier::XS))->toBe(0);
    expect($state->quantityFor(ServerTier::XL))->toBe(0);
});

test('serverless functions add a flat per function subtotal on top of the plan', function () {
    // Free plan + 3 functions × $2 = $6
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        serverlessCount: 3,
        serverlessUnitCents: 200,
    );

    expect($state->serverlessCount)->toBe(3);
    expect($state->serverlessSubtotalCents)->toBe(600);
    expect($state->monthlyTotalCents)->toBe(600);
    expect($state->isFree())->toBeFalse();
});

test('plan and managed products combine in the total', function () {
    // Starter ($9) + 4 functions ($8) = $17
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: STARTER,
        tierQuantities: ['m' => 2],
        serverlessCount: 4,
        serverlessUnitCents: 200,
    );

    expect($state->planPriceCents)->toBe(900);
    expect($state->serverlessSubtotalCents)->toBe(800);
    expect($state->monthlyTotalCents)->toBe(1700);
});

test('negative serverless count is clamped', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        serverlessCount: -5,
        serverlessUnitCents: 200,
    );

    expect($state->serverlessCount)->toBe(0);
    expect($state->serverlessSubtotalCents)->toBe(0);
});

test('cloud apps add a flat per app subtotal', function () {
    // Free + 2 apps × $5 = $10
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        cloudCount: 2,
        cloudUnitCents: 500,
    );

    expect($state->cloudCount)->toBe(2);
    expect($state->cloudSubtotalCents)->toBe(1000);
    expect($state->monthlyTotalCents)->toBe(1000);
});

test('cloud resource subtotal adds on top of the platform fee', function () {
    // Free + 2 apps × $5 platform + $28 metered resources = $38
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        cloudCount: 2,
        cloudUnitCents: 500,
        cloudResourceSubtotalCents: 2800,
    );

    expect($state->cloudCount)->toBe(2);
    expect($state->cloudSubtotalCents)->toBe(1000);
    expect($state->cloudResourceSubtotalCents)->toBe(2800);
    expect($state->managedSubtotalCents())->toBe(3800);
    expect($state->monthlyTotalCents)->toBe(3800);
});

test('negative cloud resource subtotal is clamped', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        cloudCount: 1,
        cloudUnitCents: 500,
        cloudResourceSubtotalCents: -1000,
    );

    expect($state->cloudResourceSubtotalCents)->toBe(0);
    expect($state->monthlyTotalCents)->toBe(500);
});

test('edge sites add a flat per site subtotal', function () {
    // Free + 3 sites × $2 = $6
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 3,
        edgeUnitCents: 200,
    );

    expect($state->edgeCount)->toBe(3);
    expect($state->edgeSubtotalCents)->toBe(600);
    expect($state->monthlyTotalCents)->toBe(600);
});

test('edge delivery usage adds on top and is not plan eligible', function () {
    // Pro ($19) + 1 edge ($2) + $5 usage = $26
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PRO,
        tierQuantities: ['m' => 1],
        edgeCount: 1,
        edgeUnitCents: 200,
        edgeUsageSubtotalCents: 500,
    );

    expect($state->edgeUsageSubtotalCents)->toBe(500);
    expect($state->monthlyTotalCents)->toBe(2600);
});

test('managed products and a plan all combine in the total', function () {
    // Pro ($19) + 2 cloud ($10) + 1 edge ($2) = $31
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PRO,
        tierQuantities: ['m' => 1],
        cloudCount: 2,
        cloudUnitCents: 500,
        edgeCount: 1,
        edgeUnitCents: 200,
    );

    expect($state->planPriceCents)->toBe(1900);
    expect($state->cloudSubtotalCents)->toBe(1000);
    expect($state->edgeSubtotalCents)->toBe(200);
    expect($state->managedSubtotalCents())->toBe(1200);
    expect($state->monthlyTotalCents)->toBe(3100);
});

test('negative cloud and edge counts are clamped', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        cloudCount: -2,
        cloudUnitCents: 500,
        edgeCount: -1,
        edgeUnitCents: 200,
    );

    expect($state->cloudCount)->toBe(0);
    expect($state->cloudSubtotalCents)->toBe(0);
    expect($state->edgeCount)->toBe(0);
    expect($state->edgeSubtotalCents)->toBe(0);
});

test('to array round trips for queue payloads', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: STARTER,
        tierQuantities: ['s' => 2, 'l' => 1],
    );

    $array = $state->toArray();

    expect($array['plan_key'])->toBe('starter');
    expect($array['plan_price_cents'])->toBe(900);
    expect($array['server_count'])->toBe(3);
    expect($array['tier_quantities'])->toBe(['xs' => 0, 's' => 2, 'm' => 0, 'l' => 1, 'xl' => 0]);
    expect($array['monthly_total_cents'])->toBe(900);
    expect($array['cloud_resource_subtotal_cents'])->toBe(0);
    // Back-compat keys still present for not-yet-migrated consumers.
    expect($array['base_cents'])->toBe(0);
    expect($array['server_subtotal_cents'])->toBe(900);
});
