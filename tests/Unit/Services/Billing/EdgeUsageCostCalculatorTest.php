<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing\EdgeUsageCostCalculatorTest;

use App\Modules\Billing\Services\EdgeUsageCostCalculator;
use App\Modules\Billing\Services\EdgeUsageTotals;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('dply.edge.usage_billing.enabled', true);
    Config::set('dply.edge.usage_billing.markup_percent', 0);
    Config::set('dply.edge.usage_billing.requests_cents_per_million', 50);
    Config::set('dply.edge.usage_billing.egress_cents_per_gb', 5);
    Config::set('dply.edge.usage_billing.r2_storage_cents_per_gb_month', 3);
    Config::set('dply.edge.usage_billing.included_requests_per_site', 1_000_000);
    Config::set('dply.edge.usage_billing.included_egress_gb_per_site', 10);
    Config::set('dply.edge.usage_billing.included_r2_storage_gb_per_site', 1);

    $this->calculator = app(EdgeUsageCostCalculator::class);
});

test('returns zero when usage billing disabled', function () {
    Config::set('dply.edge.usage_billing.enabled', false);

    $estimate = $this->calculator->estimate(new EdgeUsageTotals(requests: 5_000_000), 1);

    expect($estimate['subtotal_cents'])->toBe(0);
});

test('applies per site included allowances before billing', function () {
    $usage = new EdgeUsageTotals(
        requests: 2_500_000,
        bytesEgress: 15 * 1024 ** 3,
    );

    $estimate = $this->calculator->estimate($usage, 1);

    expect($estimate['billable_requests'])->toBe(1_500_000);
    expect($estimate['billable_bytes_egress'])->toBe(5 * 1024 ** 3);
    // 1.5M requests => $0.75 (75 cents) + 5 GB => $0.25 (25 cents)
    expect($estimate['subtotal_cents'])->toBe(100);
});

test('scales included allowances with edge site count', function () {
    $usage = new EdgeUsageTotals(requests: 3_000_000);

    $estimate = $this->calculator->estimate($usage, 2);

    expect($estimate['included_requests'])->toBe(2_000_000);
    expect($estimate['billable_requests'])->toBe(1_000_000);
    expect($estimate['subtotal_cents'])->toBe(50);
});

test('markup is applied on top of metered subtotal', function () {
    Config::set('dply.edge.usage_billing.markup_percent', 25);

    $usage = new EdgeUsageTotals(requests: 2_000_000);

    $estimate = $this->calculator->estimate($usage, 1);

    // 1M billable requests @ $0.50 = 50 cents, +25% = 63 cents (ceil)
    expect($estimate['subtotal_cents'])->toBe(63);
});
