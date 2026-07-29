<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing\ServerLogUsageCostCalculatorTest;

use App\Modules\Billing\Services\ServerLogUsageCostCalculator;
use App\Modules\Logs\Services\ServerLogEntitlement;
use Illuminate\Support\Facades\Config;

const GB = 1073741824; // 1024^3

/** @param array<string, mixed> $over */
function entitlement(array $over = []): ServerLogEntitlement
{
    return ServerLogEntitlement::fromConfig('pro', array_merge([
        'monthly_included_gb' => 10,
        'overage_per_gb_cents' => 50,
    ], $over));
}

beforeEach(function () {
    Config::set('server_logs.billing.enabled', true);
    $this->calculator = app(ServerLogUsageCostCalculator::class);
});

test('returns zero when billing is disabled, even over the allowance', function () {
    Config::set('server_logs.billing.enabled', false);

    $estimate = $this->calculator->estimate(entitlement(), 100 * GB);

    expect($estimate['subtotal_cents'])->toBe(0);
    expect($estimate['billable_bytes'])->toBe(0);
});

test('returns zero when usage is within the included allowance', function () {
    $estimate = $this->calculator->estimate(entitlement(), 5 * GB);

    expect($estimate['billable_bytes'])->toBe(0);
    expect($estimate['subtotal_cents'])->toBe(0);
});

test('bills per-GB overage above the included allowance', function () {
    // 10 GB included, 16 GB used => 6 GB billable @ 50¢/GB = 300¢.
    $estimate = $this->calculator->estimate(entitlement(), 16 * GB);

    expect($estimate['included_bytes'])->toBe(10 * GB);
    expect($estimate['billable_bytes'])->toBe(6 * GB);
    expect($estimate['subtotal_cents'])->toBe(300);
});

test('rounds a partial overage GB up', function () {
    // 10.5 GB used => 0.5 GB billable @ 50¢/GB => ceil(25) = 25¢.
    $estimate = $this->calculator->estimate(entitlement(), 10 * GB + GB / 2);

    expect($estimate['subtotal_cents'])->toBe(25);
});

test('a zero overage rate never bills (the default for every plan today)', function () {
    $estimate = $this->calculator->estimate(entitlement(['overage_per_gb_cents' => 0]), 100 * GB);

    expect($estimate['billable_bytes'])->toBe(90 * GB);
    expect($estimate['subtotal_cents'])->toBe(0);
});
