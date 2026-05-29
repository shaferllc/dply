<?php

namespace Tests\Feature\Services\Billing\StandardSubscriptionCreatorTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Services\Billing\DesiredBillingState;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\StandardSubscriptionCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Tests create servers via factory with `created_at = now()`, which the
    // default 1-day grace window would exclude. Zero it for the suite — a
    // dedicated computer test exercises the threshold separately.
    Config::set('subscription.standard.min_billable_age_days', 0);
    Config::set('subscription.standard.stripe', [
        'base_monthly' => 'price_base_monthly',
        'base_yearly' => 'price_base_yearly',
        'serverless' => 'price_serverless',
        'serverless_yearly' => 'price_serverless_y',
        'cloud' => 'price_cloud',
        'cloud_yearly' => 'price_cloud_y',
        'edge' => 'price_edge',
        'edge_yearly' => 'price_edge_y',
        'tiers' => [
            'xs' => 'price_tier_xs',
            's' => 'price_tier_s',
            'm' => 'price_tier_m',
            'l' => 'price_tier_l',
            'xl' => 'price_tier_xl',
        ],
        'tiers_yearly' => [
            'xs' => 'price_tier_xs_y',
            's' => 'price_tier_s_y',
            'm' => 'price_tier_m_y',
            'l' => 'price_tier_l_y',
            'xl' => 'price_tier_xl_y',
        ],
    ]);

    $this->creator = app(StandardSubscriptionCreator::class);
});

test('throws when base price is not configured', function () {
    Config::set('subscription.standard.stripe.base_monthly', '');
    $org = Organization::factory()->create();
    $desired = app(OrganizationBillingStateComputer::class)->compute($org);

    $this->expectException(RuntimeException::class);
    $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_MONTH);
});

test('builds minimum line items for empty fleet', function () {
    $org = Organization::factory()->create();
    $desired = app(OrganizationBillingStateComputer::class)->compute($org);

    $items = $this->creator->buildPriceList($desired);

    expect($items)->toBe([
        ['price' => 'price_base_monthly', 'quantity' => 1],
    ]);
});

test('omits base line item for a single xs server under free entry tier', function () {
    $org = Organization::factory()->create();
    server($org, 1, 2048);

    // A single XS server — the base fee is waived, so the subscription carries
    // only the XS tier line (which keeps it non-empty for Stripe).
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    expect($desired->baseCents)->toBe(0);

    $items = $this->creator->buildPriceList($desired);

    expect($items)->toBe([
        ['price' => 'price_tier_xs', 'quantity' => 1],
    ]);
});

test('does not require base price when base is waived', function () {
    Config::set('subscription.standard.stripe.base_monthly', '');
    $org = Organization::factory()->create();
    server($org, 1, 2048);

    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    // Single XS server waives the base, so a missing base price must not throw.
    $items = $this->creator->buildPriceList($desired);

    expect($items)->toBe([
        ['price' => 'price_tier_xs', 'quantity' => 1],
    ]);
});

test('keeps base line item once a second server appears', function () {
    $org = Organization::factory()->create();
    server($org, 1, 2048);
    server($org, 1, 2048);

    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    expect($desired->baseCents)->toBe(1500);

    $items = $this->creator->buildPriceList($desired);

    $this->assertContainsEquals(['price' => 'price_base_monthly', 'quantity' => 1], $items);
    $this->assertContainsEquals(['price' => 'price_tier_xs', 'quantity' => 2], $items);
});

test('builds a line item per non empty tier', function () {
    $org = Organization::factory()->create();
    server($org, 4, 8192);
    // M
    server($org, 4, 8192);
    // M
    server($org, 8, 16384);
    // L
    server($org, 1, 2048);

    // XS
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    $items = $this->creator->buildPriceList($desired);

    $this->assertContainsEquals(['price' => 'price_base_monthly', 'quantity' => 1], $items);
    $this->assertContainsEquals(['price' => 'price_tier_xs', 'quantity' => 1], $items);
    $this->assertContainsEquals(['price' => 'price_tier_m', 'quantity' => 2], $items);
    $this->assertContainsEquals(['price' => 'price_tier_l', 'quantity' => 1], $items);

    // No item for unused tiers; never any credit-related line items.
    foreach ($items as $item) {
        $this->assertNotSame('price_tier_s', $item['price']);
        $this->assertNotSame('price_tier_xl', $item['price']);
        $this->assertStringNotContainsString('credit', $item['price']);
        $this->assertStringNotContainsString('coupon', $item['price']);
    }
});

test('skips tiers whose stripe prices are missing', function () {
    Config::set('subscription.standard.stripe.tiers.m', '');
    $org = Organization::factory()->create();
    server($org, 4, 8192);

    // M — but tier price is empty
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());
    $items = $this->creator->buildPriceList($desired);

    foreach ($items as $item) {
        $this->assertNotSame('price_tier_m', $item['price']);
    }
});

test('yearly interval uses yearly base price and yearly tiers', function () {
    $org = Organization::factory()->create();
    server($org, 4, 8192);
    // M
    server($org, 8, 16384);

    // L
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());
    $items = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_YEAR);

    $this->assertContainsEquals(['price' => 'price_base_yearly', 'quantity' => 1], $items);
    $this->assertContainsEquals(['price' => 'price_tier_m_y', 'quantity' => 1], $items);
    $this->assertContainsEquals(['price' => 'price_tier_l_y', 'quantity' => 1], $items);

    // Critical: no mixed intervals. Stripe Checkout rejects subscriptions
    // that mix monthly + yearly line items.
    foreach ($items as $item) {
        $this->assertNotSame('price_base_monthly', $item['price']);
        $this->assertNotSame('price_tier_m', $item['price']);
        $this->assertNotSame('price_tier_l', $item['price']);
    }
});

test('tier price ids for interval returns correct set', function () {
    $monthlyIds = $this->creator->tierPriceIdsForInterval(StandardSubscriptionCreator::INTERVAL_MONTH);
    $yearlyIds = $this->creator->tierPriceIdsForInterval(StandardSubscriptionCreator::INTERVAL_YEAR);

    expect($monthlyIds['m'])->toBe('price_tier_m');
    expect($yearlyIds['m'])->toBe('price_tier_m_y');
});

test('serverless functions add an interval aware line item', function () {
    $desired = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: ['xs' => 200],
        serverlessCount: 3,
        serverlessUnitCents: 200,
    );

    $monthly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_MONTH);
    $this->assertContainsEquals(['price' => 'price_serverless', 'quantity' => 3], $monthly);

    $yearly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_YEAR);
    $this->assertContainsEquals(['price' => 'price_serverless_y', 'quantity' => 3], $yearly);
});

test('cloud and edge sites add interval aware line items', function () {
    $desired = DesiredBillingState::fromCounts(
        tierQuantities: [],
        baseCents: 1500,
        creditCents: 0,
        tierPricesCents: ['xs' => 200],
        cloudCount: 2,
        cloudUnitCents: 500,
        edgeCount: 4,
        edgeUnitCents: 200,
    );

    $monthly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_MONTH);
    $this->assertContainsEquals(['price' => 'price_cloud', 'quantity' => 2], $monthly);
    $this->assertContainsEquals(['price' => 'price_edge', 'quantity' => 4], $monthly);

    $yearly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_YEAR);
    $this->assertContainsEquals(['price' => 'price_cloud_y', 'quantity' => 2], $yearly);
    $this->assertContainsEquals(['price' => 'price_edge_y', 'quantity' => 4], $yearly);
});

test('no serverless line item when count is zero', function () {
    $org = Organization::factory()->create();
    $items = $this->creator->buildPriceList(
        app(OrganizationBillingStateComputer::class)->compute($org)
    );

    foreach ($items as $item) {
        $this->assertStringNotContainsString('serverless', $item['price']);
    }
});

function server(Organization $org, int $cpu, int $memMb): Server
{
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);

    ServerMetricSnapshot::query()->create([
        'server_id' => $server->id,
        'captured_at' => now(),
        'payload' => ['cpu_count' => $cpu, 'mem_total_kb' => $memMb * 1024],
    ]);

    return $server;
}
