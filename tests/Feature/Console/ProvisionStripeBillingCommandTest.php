<?php

namespace Tests\Feature\Console\ProvisionStripeBillingCommandTest;

use App\Services\Billing\StripeBillingProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

test('dry run lists objects without calling stripe', function () {
    Config::set('cashier.secret', 'sk_test_dummy');
    Config::set('subscription.standard.base_cents', 1500);
    Config::set('subscription.standard.annual_discount_pct', 20);
    Config::set('subscription.standard.tiers', ['xs' => 200, 's' => 500, 'm' => 1000, 'l' => 2000, 'xl' => 4000]);

    $this->artisan('dply:billing:provision-stripe', ['--dry-run' => true])
        ->expectsOutputToContain('Dry-run')
        ->expectsOutputToContain('Base monthly: $15.00')
        ->expectsOutputToContain('Base yearly:  $144.00')
        ->expectsOutputToContain('Tier XS')
        ->expectsOutputToContain('Tier XL')
        ->expectsOutputToContain('dply Enterprise')
        ->assertOk();
});

test('fails loudly when stripe secret is missing', function () {
    Config::set('cashier.secret', '');

    $this->artisan('dply:billing:provision-stripe')
        ->expectsOutputToContain('STRIPE_SECRET')
        ->assertFailed();
});

test('format env emits expected lines', function () {
    $result = [
        StripeBillingProvisioner::ROLE_BASE_PRODUCT => 'prod_std',
        StripeBillingProvisioner::ROLE_BASE_MONTHLY => 'price_bm',
        StripeBillingProvisioner::ROLE_BASE_YEARLY => 'price_by',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'xs' => 'price_xs',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'s' => 'price_s',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'m' => 'price_m',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'l' => 'price_l',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'xl' => 'price_xl',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'xs'.StripeBillingProvisioner::ROLE_TIER_YEARLY_SUFFIX => 'price_xs_y',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'m'.StripeBillingProvisioner::ROLE_TIER_YEARLY_SUFFIX => 'price_m_y',
        StripeBillingProvisioner::ROLE_TIER_PREFIX.'xl'.StripeBillingProvisioner::ROLE_TIER_YEARLY_SUFFIX => 'price_xl_y',
        StripeBillingProvisioner::ROLE_ENTERPRISE_PRODUCT => 'prod_ent',
    ];

    $env = StripeBillingProvisioner::formatEnv($result);

    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_BASE_MONTHLY=price_bm', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_BASE_YEARLY=price_by', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_TIER_XS=price_xs', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_TIER_M=price_m', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_TIER_XL=price_xl', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_TIER_XS_YEARLY=price_xs_y', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_TIER_M_YEARLY=price_m_y', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_TIER_XL_YEARLY=price_xl_y', $env);

    // No coupon env var — credit retired.
    $this->assertStringNotContainsString('STRIPE_COUPON_STANDARD_CREDIT', $env);

    // Product IDs aren't env-bound (operators don't need them at runtime).
    $this->assertStringNotContainsString('prod_std', $env);
    $this->assertStringNotContainsString('prod_ent', $env);
});

test('format env skips missing roles', function () {
    $env = StripeBillingProvisioner::formatEnv([
        StripeBillingProvisioner::ROLE_BASE_MONTHLY => 'price_bm',
    ]);

    expect($env)->toBe('STRIPE_PRICE_STANDARD_BASE_MONTHLY=price_bm');
});
