<?php

declare(strict_types=1);

namespace Tests\Unit\Queue\QueueUsageCostCalculatorTest;

use App\Modules\Billing\Services\QueueUsageCostCalculator;
use App\Modules\Queue\Support\QueueEntitlement;

function queueEntitlement(int $includedJobs = 1_000_000, int $overageCents = 50): QueueEntitlement
{
    return QueueEntitlement::fromConfig('standard', [
        'available' => true,
        'monthly_included_jobs' => $includedJobs,
        'overage_per_million_jobs_cents' => $overageCents,
    ]);
}

test('it is dark until billing is switched on', function () {
    // Ships disabled, same staging as dply Logs: the numbers exist so pricing
    // can be calibrated against them before anyone is charged.
    config(['queue_service.billing.enabled' => false]);

    $estimate = app(QueueUsageCostCalculator::class)->estimate(queueEntitlement(), 5_000_000);

    expect($estimate['subtotal_cents'])->toBe(0);
    expect($estimate['billable_jobs'])->toBe(0);
    // Usage is still reported — only the price is suppressed.
    expect($estimate['used_jobs'])->toBe(5_000_000);
});

test('usage inside the allowance costs nothing', function () {
    config(['queue_service.billing.enabled' => true]);

    $estimate = app(QueueUsageCostCalculator::class)->estimate(queueEntitlement(), 900_000);

    expect($estimate['subtotal_cents'])->toBe(0);
    expect($estimate['billable_jobs'])->toBe(0);
});

test('overage is billed per million above the allowance', function () {
    config(['queue_service.billing.enabled' => true]);

    $estimate = app(QueueUsageCostCalculator::class)->estimate(queueEntitlement(), 3_000_000);

    expect($estimate['billable_jobs'])->toBe(2_000_000);
    expect($estimate['subtotal_cents'])->toBe(100);
});

test('a partial million still costs its fraction, rounded up', function () {
    // Rounding down would bill nothing for anything under the unit, which is
    // most real overage.
    config(['queue_service.billing.enabled' => true]);

    $estimate = app(QueueUsageCostCalculator::class)->estimate(queueEntitlement(), 1_100_000);

    expect($estimate['billable_jobs'])->toBe(100_000);
    expect($estimate['subtotal_cents'])->toBe(5);
});

test('a zero rate bills nothing even when enabled', function () {
    config(['queue_service.billing.enabled' => true]);

    $estimate = app(QueueUsageCostCalculator::class)->estimate(queueEntitlement(overageCents: 0), 9_000_000);

    expect($estimate['subtotal_cents'])->toBe(0);
});

test('an unlimited plan is never over its allowance', function () {
    // 0 included means unlimited — the fail-open convention these entitlements
    // inherit from dply Logs.
    $calculator = app(QueueUsageCostCalculator::class);

    expect($calculator->isOverIncluded(queueEntitlement(includedJobs: 0), 50_000_000))->toBeFalse();
});

test('over-allowance is reported even while billing is dark', function () {
    // The warning is about the allowance, not the invoice — an operator should
    // learn the number before it starts costing anything.
    config(['queue_service.billing.enabled' => false]);
    $calculator = app(QueueUsageCostCalculator::class);

    expect($calculator->isOverIncluded(queueEntitlement(), 1_000_001))->toBeTrue();
    expect($calculator->isOverIncluded(queueEntitlement(), 1_000_000))->toBeFalse();
});
