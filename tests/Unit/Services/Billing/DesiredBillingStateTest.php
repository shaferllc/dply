<?php

namespace Tests\Unit\Services\Billing\DesiredBillingStateTest;

use App\Enums\ServerTier;
use App\Services\Billing\DesiredBillingState;

const TIER_PRICES = ['xs' => 200, 's' => 500, 'm' => 1000, 'l' => 2000, 'xl' => 4000];

const BASE = 2500;

const CREDIT = 1000;

test('empty fleet bills only the base', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->serverCount())->toBe(0);
    expect($state->serverSubtotalCents)->toBe(0);
    expect($state->appliedCreditCents)->toBe(0);
    expect($state->monthlyTotalCents)->toBe(2500);
});

test('one m server is fully absorbed by credit', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['m' => 1],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->serverCount())->toBe(1);
    expect($state->serverSubtotalCents)->toBe(1000);
    expect($state->appliedCreditCents)->toBe(1000);
    expect($state->monthlyTotalCents)->toBe(2500);
});

test('one xs server credit caps at server subtotal', function () {
    // $2 server + $25 base; $10 credit can only absorb $2 — no negative bill.
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['xs' => 1],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->serverSubtotalCents)->toBe(200);
    expect($state->appliedCreditCents)->toBe(200);
    expect($state->monthlyTotalCents)->toBe(2500);
});

test('mixed fleet computes per tier totals', function () {
    // 2 M ($10 ea) + 1 L ($20) + 1 XS ($2) = $42 subtotal
    // $25 base - $10 credit + $42 = $57 = 5700 cents
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['m' => 2, 'l' => 1, 'xs' => 1],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->serverCount())->toBe(4);
    expect($state->serverSubtotalCents)->toBe(4200);
    expect($state->appliedCreditCents)->toBe(1000);
    expect($state->monthlyTotalCents)->toBe(5700);
});

test('xl only customer still gets full credit', function () {
    // 1 XL ($40) + $25 base - $10 credit = $55
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['xl' => 1],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->serverCount())->toBe(1);
    expect($state->serverSubtotalCents)->toBe(4000);
    expect($state->appliedCreditCents)->toBe(1000);
    expect($state->monthlyTotalCents)->toBe(5500);
});

test('unknown tier keys are ignored', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['xs' => 1, 'mythical_tier' => 99],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->serverCount())->toBe(1);
    expect($state->serverSubtotalCents)->toBe(200);
});

test('negative quantities are clamped to zero', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['xs' => -5],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->serverCount())->toBe(0);
    expect($state->serverSubtotalCents)->toBe(0);
});

test('quantity for returns zero for unbought tiers', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['m' => 3],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    expect($state->quantityFor(ServerTier::M))->toBe(3);
    expect($state->quantityFor(ServerTier::XS))->toBe(0);
    expect($state->quantityFor(ServerTier::XL))->toBe(0);
});

test('serverless functions add a flat per function subtotal', function () {
    // 3 functions × $2 + $15 base = $21 = 2100 cents
    $state = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: TIER_PRICES,
        serverlessCount: 3,
        serverlessUnitCents: 200,
    );

    expect($state->serverlessCount)->toBe(3);
    expect($state->serverlessSubtotalCents)->toBe(600);
    expect($state->monthlyTotalCents)->toBe(2100);
});

test('serverless and servers combine in the total', function () {
    // 2 M servers ($20) + 4 functions ($8) + $15 base = $43 = 4300 cents
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['m' => 2],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: TIER_PRICES,
        serverlessCount: 4,
        serverlessUnitCents: 200,
    );

    expect($state->serverSubtotalCents)->toBe(2000);
    expect($state->serverlessSubtotalCents)->toBe(800);
    expect($state->monthlyTotalCents)->toBe(4300);
});

test('negative serverless count is clamped', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: TIER_PRICES,
        serverlessCount: -5,
        serverlessUnitCents: 200,
    );

    expect($state->serverlessCount)->toBe(0);
    expect($state->serverlessSubtotalCents)->toBe(0);
});

test('cloud apps add a flat per app subtotal', function () {
    // 2 apps × $5 + $15 base = $25 = 2500 cents
    $state = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: TIER_PRICES,
        cloudCount: 2,
        cloudUnitCents: 500,
    );

    expect($state->cloudCount)->toBe(2);
    expect($state->cloudSubtotalCents)->toBe(1000);
    expect($state->monthlyTotalCents)->toBe(2500);
});

test('edge sites add a flat per site subtotal', function () {
    // 3 sites × $2 + $15 base = $21 = 2100 cents
    $state = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: TIER_PRICES,
        edgeCount: 3,
        edgeUnitCents: 200,
    );

    expect($state->edgeCount)->toBe(3);
    expect($state->edgeSubtotalCents)->toBe(600);
    expect($state->monthlyTotalCents)->toBe(2100);
});

test('edge delivery usage is added after credit and is not credit eligible', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['m' => 1],
        baseCents: 1500,
        creditCents: 1000,
        tierPricesCents: TIER_PRICES,
        edgeCount: 1,
        edgeUnitCents: 200,
        edgeUsageSubtotalCents: 500,
    );

    expect($state->appliedCreditCents)->toBe(1000);
    // $15 base + $10 M + $2 edge - $10 credit + $5 usage = $22
    expect($state->monthlyTotalCents)->toBe(2200);
});

test('managed products and servers combine and credit applies to managed subtotal', function () {
    // 1 M server ($10) + 2 cloud ($10) + 1 edge ($2) + $15 base - $10 credit = $27
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['m' => 1],
        baseCents: 1500,
        creditCents: 1000,
        tierPricesCents: TIER_PRICES,
        cloudCount: 2,
        cloudUnitCents: 500,
        edgeCount: 1,
        edgeUnitCents: 200,
    );

    expect($state->serverSubtotalCents)->toBe(1000);
    expect($state->cloudSubtotalCents)->toBe(1000);
    expect($state->edgeSubtotalCents)->toBe(200);
    expect($state->appliedCreditCents)->toBe(1000);
    expect($state->monthlyTotalCents)->toBe(2700);
});

test('negative cloud and edge counts are clamped', function () {
    $state = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: TIER_PRICES,
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
    $state = DesiredBillingState::fromCounts(
        tierQuantities: ['s' => 2, 'l' => 1],
        baseCents: BASE,
        creditCents: CREDIT,
        tierPricesCents: TIER_PRICES,
    );

    $array = $state->toArray();

    expect($array['tier_quantities'])->toBe(['xs' => 0, 's' => 2, 'm' => 0, 'l' => 1, 'xl' => 0]);
    expect($array['base_cents'])->toBe(2500);
    expect($array['server_subtotal_cents'])->toBe(3000);
    expect($array['applied_credit_cents'])->toBe(1000);
    expect($array['monthly_total_cents'])->toBe(4500);
});
