<?php

namespace Tests\Unit\Services\Billing\ServerlessUsageCostCalculatorTest;

use App\Modules\Billing\Services\ServerlessUsageCostCalculator;
use App\Modules\Billing\Services\ServerlessUsageTotals;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('dply.serverless.usage_billing.enabled', true);
    Config::set('dply.serverless.usage_billing.markup_percent', 0);
    Config::set('dply.serverless.usage_billing.invocations_cents_per_million', 40);
    Config::set('dply.serverless.usage_billing.gib_seconds_cents_per_100k', 185);
    Config::set('dply.serverless.usage_billing.included_invocations_per_function', 1_000_000);
    Config::set('dply.serverless.usage_billing.included_gib_seconds_per_function', 90_000);

    $this->calc = new ServerlessUsageCostCalculator;
});

test('returns zero when disabled', function () {
    Config::set('dply.serverless.usage_billing.enabled', false);

    $estimate = $this->calc->estimate(new ServerlessUsageTotals(invocations: 5_000_000), 1);

    expect($estimate['subtotal_cents'])->toBe(0);
});

test('returns zero with no managed functions', function () {
    $estimate = $this->calc->estimate(new ServerlessUsageTotals(invocations: 5_000_000), 0);

    expect($estimate['subtotal_cents'])->toBe(0);
});

test('usage inside the included allowance is free', function () {
    // 1 function → 1M included invocations; 1M used is exactly covered.
    $estimate = $this->calc->estimate(new ServerlessUsageTotals(invocations: 1_000_000), 1);

    expect($estimate['billable_invocations'])->toBe(0);
    expect($estimate['subtotal_cents'])->toBe(0);
});

test('only invocations above the included allowance are billed', function () {
    // 1 function (1M included) + 3M used = 2M billable × 40¢/million = 80¢.
    $estimate = $this->calc->estimate(new ServerlessUsageTotals(invocations: 3_000_000), 1);

    expect($estimate['billable_invocations'])->toBe(2_000_000);
    expect($estimate['subtotal_cents'])->toBe(80);
});

test('allowance scales with the number of managed functions', function () {
    // 3 functions → 3M included; 3M used is fully covered.
    $estimate = $this->calc->estimate(new ServerlessUsageTotals(invocations: 3_000_000), 3);

    expect($estimate['subtotal_cents'])->toBe(0);
});

test('markup is applied on top of the metered subtotal', function () {
    Config::set('dply.serverless.usage_billing.markup_percent', 50);

    // 2M billable × 40¢ = 80¢, +50% = 120¢.
    $estimate = $this->calc->estimate(new ServerlessUsageTotals(invocations: 3_000_000), 1);

    expect($estimate['subtotal_cents'])->toBe(120);
});

test('gib seconds above the allowance are billed when reported', function () {
    // 0 included via 0 functions would zero out; use 1 function = 90k included.
    // 290k used → 200k billable × 185¢/100k = 370¢.
    $estimate = $this->calc->estimate(new ServerlessUsageTotals(gibSeconds: 290_000), 1);

    expect($estimate['billable_gib_seconds'])->toBe(200_000);
    expect($estimate['subtotal_cents'])->toBe(370);
});
