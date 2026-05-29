<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\OrganizationBillingSnapshot;
use App\Services\Billing\BillingForecastCalculator;
use App\Services\Billing\DesiredBillingState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('forecast normalizes yearly subscriptions and projects month end', function () {
    config(['subscription.standard.annual_discount_pct' => 20]);

    // Plan-fee portion of $15 plus $4.50 of Edge usage → $19.50 monthly.
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'custom', 'label' => 'Custom', 'price_cents' => 1500, 'max_servers' => null],
        edgeUsageSubtotalCents: 450,
    );

    $baseline = new OrganizationBillingSnapshot([
        'monthly_total_cents' => 1700,
    ]);

    $forecast = app(BillingForecastCalculator::class)->calculate(
        state: $state,
        subscriptionInterval: 'year',
        snapshotThirtyDaysAgo: $baseline,
        asOf: now()->setDate(2026, 5, 10),
    );

    expect($forecast['mrr_cents'])->toBe(1560)
        ->and($forecast['arr_cents'])->toBe(18_720)
        ->and($forecast['fixed_cents'])->toBe(1500)
        ->and($forecast['projected_edge_usage_cents'])->toBeGreaterThan(0)
        ->and($forecast['projected_month_end_cents'])->toBeGreaterThan(1500)
        ->and($forecast['delta_vs_thirty_days_cents'])->toBe(250);
});

test('forecast keeps monthly mrr and null delta without baseline snapshot', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'custom', 'label' => 'Custom', 'price_cents' => 2000, 'max_servers' => null],
    );

    $forecast = app(BillingForecastCalculator::class)->calculate($state, 'month', null, now()->setDate(2026, 5, 20));

    expect($forecast['mrr_cents'])->toBe(2000)
        ->and($forecast['arr_cents'])->toBe(24_000)
        ->and($forecast['delta_vs_thirty_days_cents'])->toBeNull();
});
