<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\ServerlessAssetBillingTest;

use App\Models\Site;
use App\Modules\Billing\Services\ServerlessUsageCostCalculator;
use App\Modules\Billing\Services\ServerlessUsageTotals;
use App\Modules\Serverless\Services\ServerlessAssetEgressReader;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'dply.serverless.usage_billing.enabled' => true,
        'dply.serverless.usage_billing.markup_percent' => 0,
        'dply.serverless.usage_billing.invocations_cents_per_million' => 0,
        'dply.serverless.usage_billing.gib_seconds_cents_per_100k' => 0,
        'dply.serverless.usage_billing.included_invocations_per_function' => 0,
        'dply.serverless.usage_billing.included_gib_seconds_per_function' => 0,
        'dply.serverless.usage_billing.asset_storage_cents_per_gb_month' => 2,
        'dply.serverless.usage_billing.asset_egress_cents_per_gb' => 1,
        'dply.serverless.usage_billing.included_asset_storage_gb_per_function' => 1,
        'dply.serverless.usage_billing.included_asset_egress_gb_per_function' => 100,
    ]);
});

test('a normal site pays nothing for its assets', function () {
    // A real Vite build is single-digit MB against a 1 GiB allowance.
    $usage = new ServerlessUsageTotals(
        assetStorageBytes: 8 * 1024 ** 2,
        assetBytesEgress: 2 * 1024 ** 3,
    );

    $estimate = app(ServerlessUsageCostCalculator::class)->estimate($usage, functionCount: 1);

    expect($estimate['subtotal_cents'])->toBe(0);
    expect($estimate['billable_asset_storage_bytes'])->toBe(0);
    expect($estimate['billable_asset_bytes_egress'])->toBe(0);
});

test('only the overage above the allowance is billed', function () {
    $usage = new ServerlessUsageTotals(
        assetStorageBytes: 11 * 1024 ** 3,   // 10 GiB over
        assetBytesEgress: 150 * 1024 ** 3,   // 50 GiB over
    );

    $estimate = app(ServerlessUsageCostCalculator::class)->estimate($usage, functionCount: 1);

    expect($estimate['billable_asset_storage_bytes'])->toBe(10 * 1024 ** 3);
    expect($estimate['billable_asset_bytes_egress'])->toBe(50 * 1024 ** 3);
    // 10 GiB * 2c + 50 GiB * 1c
    expect($estimate['subtotal_cents'])->toBe(70);
});

test('allowances scale with the number of functions', function () {
    $usage = new ServerlessUsageTotals(assetStorageBytes: 3 * 1024 ** 3);

    expect(app(ServerlessUsageCostCalculator::class)->estimate($usage, functionCount: 3)['subtotal_cents'])
        ->toBe(0);
});

test('markup applies on top of the cost-floor rates', function () {
    config(['dply.serverless.usage_billing.markup_percent' => 40]);

    $usage = new ServerlessUsageTotals(assetStorageBytes: 11 * 1024 ** 3);

    // 10 GiB * 2c = 20c, +40%
    expect(app(ServerlessUsageCostCalculator::class)->estimate($usage, functionCount: 1)['subtotal_cents'])
        ->toBe(28);
});

test('assets are not billed while usage billing is off', function () {
    config(['dply.serverless.usage_billing.enabled' => false]);

    $usage = new ServerlessUsageTotals(assetStorageBytes: 500 * 1024 ** 3);

    expect(app(ServerlessUsageCostCalculator::class)->estimate($usage, functionCount: 1)['subtotal_cents'])
        ->toBe(0);
});

/**
 * A custom asset domain's origin is the site's own default asset hostname, so
 * Cloudflare logs the same bytes twice. Billing the sum would double-charge.
 */
test('a site with a custom asset domain bills only the customer-facing hostname', function () {
    $site = Site::factory()->create([
        'meta' => ['serverless' => [
            'proxy_slug' => 'orders-a1b2c3d4',
            'assets' => ['custom_hostnames' => ['cdn.acme.com']],
        ]],
    ]);

    expect(app(ServerlessAssetEgressReader::class)->billableHostnames($site))
        ->toBe(['cdn.acme.com']);
});

test('a site without a custom domain bills its default hostname', function () {
    $site = Site::factory()->create([
        'meta' => ['serverless' => ['proxy_slug' => 'orders-a1b2c3d4']],
    ]);

    expect(app(ServerlessAssetEgressReader::class)->billableHostnames($site))
        ->toBe([(string) \App\Modules\Serverless\Support\ServerlessAssetHost::hostname($site)]);
});
