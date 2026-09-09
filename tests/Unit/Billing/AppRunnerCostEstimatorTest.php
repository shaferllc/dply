<?php

declare(strict_types=1);

namespace Tests\Unit\Billing\AppRunnerCostEstimatorTest;

use App\Modules\Billing\Services\ManagedProductCostEstimator;
use App\Modules\Cloud\Backends\AwsAppRunnerBackend;

test('app runner monthly estimate matches vcpu and memory rates', function () {
    config([
        'subscription.standard.app_runner_hours_per_month' => 730,
        'subscription.standard.app_runner_vcpu_usd_per_hour' => 0.064,
        'subscription.standard.app_runner_memory_gb_usd_per_hour' => 0.007,
    ]);

    $estimator = new ManagedProductCostEstimator;

    // small = 0.25 vCPU + 0.5 GB → (0.016 + 0.0035) × 730 = 14.235 → 14.24
    expect($estimator->appRunnerMonthlyUsd('small', 1))->toBe(14.24);

    // medium × 2 instances
    expect($estimator->appRunnerMonthlyUsd('medium', 2))->toBe(
        round(((0.5 * 0.064) + (1.0 * 0.007)) * 730 * 2, 2),
    );
});

test('app runner compute map stays aligned with backend size tiers', function () {
    foreach (['small', 'medium', 'large', 'xlarge', 'small-pro', 'medium-pro'] as $tier) {
        $compute = AwsAppRunnerBackend::computeForSizeTier($tier);
        expect($compute['vcpu'])->toBe(((int) $compute['cpu']) / 1024.0);
        expect($compute['memory_gb'])->toBe(((int) $compute['memory']) / 1024.0);
    }

    expect(AwsAppRunnerBackend::computeForSizeTier('small')['vcpu'])->toBe(0.25);
    expect(AwsAppRunnerBackend::computeForSizeTier('xlarge-pro')['memory_gb'])->toBe(4.0);
});
test('cloud pricing summary applies markup to the same rates the biller uses', function () {
    config([
        'subscription.standard.cloud_cents' => 500,
        'subscription.standard.cloud_markup_percent' => 40,
        'subscription.standard.cloud_container_cents' => ['small' => 500],
        'subscription.standard.cloud_database_cents' => ['small' => 1500],
    ]);

    $summary = (new ManagedProductCostEstimator)->cloudPricingSummary();

    expect($summary['flat_cents'])->toBe(500)
        ->and($summary['markup_percent'])->toBe(40)
        ->and($summary['small_container_cents'])->toBe(700)
        ->and($summary['small_database_cents'])->toBe(2100);
});
