<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\SubscriptionPlanResolver;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

beforeEach(function () {
    Config::set('subscription.standard.plans', [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1, 'max_sites' => 1],
        'starter' => ['label' => 'Starter', 'price_cents' => 900, 'max_servers' => 3, 'max_sites' => 10],
        'pro' => ['label' => 'Pro', 'price_cents' => 1900, 'max_servers' => 10, 'max_sites' => 30],
        'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null, 'max_sites' => null],
    ]);
    Config::set('subscription.standard.stripe.plans', [
        'starter' => 'price_starter_m',
        'pro' => 'price_pro_m',
        'business' => 'price_business_m',
    ]);
    Config::set('subscription.standard.stripe.plans_yearly', [
        'starter' => 'price_starter_y',
        'pro' => 'price_pro_y',
        'business' => 'price_business_y',
    ]);

    $this->resolver = new SubscriptionPlanResolver;
});

test('zero or one server resolves to the free plan', function () {
    expect($this->resolver->resolveForServerCount(0)['key'])->toBe('free');
    expect($this->resolver->resolveForServerCount(1)['key'])->toBe('free');
    expect($this->resolver->resolveForServerCount(1)['price_cents'])->toBe(0);
});

test('two and three servers resolve to starter', function () {
    expect($this->resolver->resolveForServerCount(2)['key'])->toBe('starter');
    expect($this->resolver->resolveForServerCount(3)['key'])->toBe('starter');
    expect($this->resolver->resolveForServerCount(3)['price_cents'])->toBe(900);
});

test('four through ten servers resolve to pro', function () {
    expect($this->resolver->resolveForServerCount(4)['key'])->toBe('pro');
    expect($this->resolver->resolveForServerCount(10)['key'])->toBe('pro');
    expect($this->resolver->resolveForServerCount(10)['price_cents'])->toBe(1900);
});

test('eleven or more servers resolve to unlimited business', function () {
    expect($this->resolver->resolveForServerCount(11)['key'])->toBe('business');
    expect($this->resolver->resolveForServerCount(500)['key'])->toBe('business');
    expect($this->resolver->resolveForServerCount(500)['max_servers'])->toBeNull();
});

test('negative counts clamp to the free plan', function () {
    expect($this->resolver->resolveForServerCount(-5)['key'])->toBe('free');
});

test('resolve by key returns the normalized plan', function () {
    $pro = $this->resolver->resolveByKey('pro');

    expect($pro['key'])->toBe('pro');
    expect($pro['label'])->toBe('Pro');
    expect($pro['price_cents'])->toBe(1900);
    expect($pro['max_servers'])->toBe(10);
    expect($pro['max_sites'])->toBe(30);
});

test('plans expose their site ceilings', function () {
    expect($this->resolver->resolveByKey('free')['max_sites'])->toBe(1);
    expect($this->resolver->resolveByKey('starter')['max_sites'])->toBe(10);
    expect($this->resolver->resolveByKey('business')['max_sites'])->toBeNull();
});

test('site ceiling tracks the server-count plan', function () {
    // 1 server -> free -> 1 site; 5 servers -> pro -> 30 sites.
    expect($this->resolver->resolveForServerCount(1)['max_sites'])->toBe(1);
    expect($this->resolver->resolveForServerCount(5)['max_sites'])->toBe(30);
    expect($this->resolver->resolveForServerCount(50)['max_sites'])->toBeNull();
});

test('resolve by unknown key throws', function () {
    $this->resolver->resolveByKey('enterprise-mega');
})->throws(InvalidArgumentException::class);

test('stripe price id resolves per interval', function () {
    expect($this->resolver->stripePriceId('pro', SubscriptionPlanResolver::INTERVAL_MONTH))->toBe('price_pro_m');
    expect($this->resolver->stripePriceId('pro', SubscriptionPlanResolver::INTERVAL_YEAR))->toBe('price_pro_y');
});

test('free plan has no stripe price', function () {
    expect($this->resolver->stripePriceId('free', SubscriptionPlanResolver::INTERVAL_MONTH))->toBe('');
});

test('unknown interval throws', function () {
    $this->resolver->stripePriceId('pro', 'weekly');
})->throws(InvalidArgumentException::class);

test('is paid plan is false only for free', function () {
    expect($this->resolver->isPaidPlan('free'))->toBeFalse();
    expect($this->resolver->isPaidPlan('starter'))->toBeTrue();
    expect($this->resolver->isPaidPlan('business'))->toBeTrue();
});

test('all returns every plan cheapest first', function () {
    $keys = array_column($this->resolver->all(), 'key');

    expect($keys)->toBe(['free', 'starter', 'pro', 'business']);
});

test('falls back to the most expensive plan when no ceiling matches', function () {
    // A config with no unlimited (null) ceiling: an oversized fleet must still
    // resolve to the priciest plan rather than under-billing.
    Config::set('subscription.standard.plans', [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1],
        'capped' => ['label' => 'Capped', 'price_cents' => 5000, 'max_servers' => 5],
    ]);

    expect($this->resolver->resolveForServerCount(99)['key'])->toBe('capped');
});
