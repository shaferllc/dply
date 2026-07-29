<?php

namespace Tests\Feature\Console\ProvisionStripeBillingCommandTest;

use App\Modules\Billing\Services\StripeBillingProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

test('dry run lists objects without calling stripe', function () {
    Config::set('cashier.secret', 'sk_test_dummy');
    Config::set('subscription.standard.annual_discount_pct', 20);
    Config::set('subscription.standard.plans', [
        'free' => ['label' => 'Free', 'price_cents' => 0, 'max_servers' => 1],
        'starter' => ['label' => 'Starter', 'price_cents' => 900, 'max_servers' => 3],
        'pro' => ['label' => 'Pro', 'price_cents' => 1900, 'max_servers' => 10],
        'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null],
    ]);

    // Each expectsOutputToContain is matched against a single write call, so
    // assert at most one substring per emitted line.
    $this->artisan('dply:billing:provision-stripe', ['--dry-run' => true])
        ->expectsOutputToContain('Dry-run')
        ->expectsOutputToContain('Plans (metered by BYO server count)')
        ->expectsOutputToContain('free, no Stripe object')
        ->expectsOutputToContain('$9.00/mo')
        ->expectsOutputToContain('$39.00/mo')
        ->expectsOutputToContain('dply serverless function')
        ->expectsOutputToContain('dply Cloud app')
        ->expectsOutputToContain('Per app $5.00/mo')
        ->expectsOutputToContain('dply Edge site')
        ->expectsOutputToContain('Per site $2.00/mo')
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
        StripeBillingProvisioner::ROLE_PLAN_PRODUCT_PREFIX.'starter' => 'prod_starter',
        StripeBillingProvisioner::ROLE_PLAN_PREFIX.'starter' => 'price_starter',
        StripeBillingProvisioner::ROLE_PLAN_PREFIX.'starter'.StripeBillingProvisioner::ROLE_PLAN_YEARLY_SUFFIX => 'price_starter_y',
        StripeBillingProvisioner::ROLE_PLAN_PRODUCT_PREFIX.'pro' => 'prod_pro',
        StripeBillingProvisioner::ROLE_PLAN_PREFIX.'pro' => 'price_pro',
        StripeBillingProvisioner::ROLE_PLAN_PREFIX.'pro'.StripeBillingProvisioner::ROLE_PLAN_YEARLY_SUFFIX => 'price_pro_y',
        StripeBillingProvisioner::ROLE_PLAN_PREFIX.'business' => 'price_business',
        StripeBillingProvisioner::ROLE_PLAN_PREFIX.'business'.StripeBillingProvisioner::ROLE_PLAN_YEARLY_SUFFIX => 'price_business_y',
        StripeBillingProvisioner::ROLE_SERVERLESS_MONTHLY => 'price_sl',
        StripeBillingProvisioner::ROLE_SERVERLESS_YEARLY => 'price_sl_y',
        StripeBillingProvisioner::ROLE_CLOUD_MONTHLY => 'price_cloud',
        StripeBillingProvisioner::ROLE_CLOUD_YEARLY => 'price_cloud_y',
        StripeBillingProvisioner::ROLE_EDGE_MONTHLY => 'price_edge',
        StripeBillingProvisioner::ROLE_EDGE_YEARLY => 'price_edge_y',
        StripeBillingProvisioner::ROLE_ENTERPRISE_PRODUCT => 'prod_ent',
    ];

    $env = StripeBillingProvisioner::formatEnv($result);

    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_STARTER=price_starter', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_STARTER_YEARLY=price_starter_y', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_PRO=price_pro', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_PRO_YEARLY=price_pro_y', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_BUSINESS=price_business', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_BUSINESS_YEARLY=price_business_y', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_SERVERLESS=price_sl', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_SERVERLESS_YEARLY=price_sl_y', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_CLOUD=price_cloud', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_CLOUD_YEARLY=price_cloud_y', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_EDGE=price_edge', $env);
    $this->assertStringContainsString('STRIPE_PRICE_STANDARD_EDGE_YEARLY=price_edge_y', $env);

    // Product IDs aren't env-bound (operators don't need them at runtime).
    $this->assertStringNotContainsString('prod_starter', $env);
    $this->assertStringNotContainsString('prod_pro', $env);
    $this->assertStringNotContainsString('prod_ent', $env);
});

test('format env skips product roles and emits only plan + managed prices', function () {
    $env = StripeBillingProvisioner::formatEnv([
        StripeBillingProvisioner::ROLE_PLAN_PREFIX.'starter' => 'price_starter',
    ]);

    expect($env)->toBe('STRIPE_PRICE_STANDARD_STARTER=price_starter');
});
