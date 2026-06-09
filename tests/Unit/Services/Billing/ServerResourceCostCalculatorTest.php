<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing\ServerResourceCostCalculatorTest;

use App\Models\Server;
use App\Services\Billing\ServerResourceCostCalculator;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('subscription.standard.managed_server_markup_percent', 60);
    Config::set('subscription.standard.managed_server_cents', [
        'cx22' => 450,
        'cx32' => 740,
    ]);
    $this->calculator = new ServerResourceCostCalculator;
});

test('empty collection costs nothing', function () {
    expect($this->calculator->subtotalCents(collect()))->toBe(0);
});

test('applies markup to a single size', function () {
    // $4.50 × 1.6 = $7.20.
    expect($this->calculator->monthlyCentsForSize('cx22'))->toBe(720);
    expect($this->calculator->monthlyCentsForSize('cx32'))->toBe(1184);
});

test('sums marked-up cost across managed servers', function () {
    $servers = collect([
        new Server(['size' => 'cx22']),
        new Server(['size' => 'cx32']),
    ]);

    // 720 + 1184 = 1904.
    expect($this->calculator->subtotalCents($servers))->toBe(1904);
});

test('unknown size falls back to the cheapest configured rate', function () {
    // Cheapest raw is cx22 (450) → 450 × 1.6 = 720.
    expect($this->calculator->monthlyCentsForSize('does-not-exist'))->toBe(720);
});

test('zero markup bills the raw provider cost', function () {
    Config::set('subscription.standard.managed_server_markup_percent', 0);

    expect($this->calculator->monthlyCentsForSize('cx22'))->toBe(450);
});
