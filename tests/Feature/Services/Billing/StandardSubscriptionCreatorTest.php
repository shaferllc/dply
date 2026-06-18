<?php

namespace Tests\Feature\Services\Billing\StandardSubscriptionCreatorTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\StandardSubscriptionCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;

uses(RefreshDatabase::class);

const FREE = ['key' => 'free', 'label' => 'Free', 'price_cents' => 0, 'max_servers' => 1];

beforeEach(function () {
    // Tests create servers via factory with `created_at = now()`, which the
    // default 1-day grace window would exclude. Zero it for the suite — a
    // dedicated computer test exercises the threshold separately.
    Config::set('subscription.standard.min_billable_age_days', 0);
    Config::set('subscription.standard.stripe', [
        'plans' => [
            'starter' => 'price_starter',
            'pro' => 'price_pro',
            'business' => 'price_business',
        ],
        'plans_yearly' => [
            'starter' => 'price_starter_y',
            'pro' => 'price_pro_y',
            'business' => 'price_business_y',
        ],
        'serverless' => 'price_serverless',
        'serverless_yearly' => 'price_serverless_y',
        'cloud' => 'price_cloud',
        'cloud_yearly' => 'price_cloud_y',
        'edge' => 'price_edge',
        'edge_yearly' => 'price_edge_y',
        'edge_usage' => 'price_edge_usage',
    ]);

    $this->creator = app(StandardSubscriptionCreator::class);
});

test('throws when the resolved plan price is not configured', function () {
    Config::set('subscription.standard.stripe.plans.starter', '');
    $org = Organization::factory()->create();
    // Two servers → Starter plan, whose price is now missing.
    server($org, 1, 2048);
    server($org, 1, 2048);
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    $this->expectException(RuntimeException::class);
    $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_MONTH);
});

test('builds no line items for an empty free fleet', function () {
    $org = Organization::factory()->create();
    $desired = app(OrganizationBillingStateComputer::class)->compute($org);

    // Free plan, nothing managed — there is nothing for Stripe to bill.
    expect($this->creator->buildPriceList($desired))->toBe([]);
});

test('a single server stays on the free plan with no line items', function () {
    $org = Organization::factory()->create();
    server($org, 1, 2048);

    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    expect($desired->planKey)->toBe('free');
    expect($this->creator->buildPriceList($desired))->toBe([]);
});

test('emits a single flat plan line for a paid fleet', function () {
    $org = Organization::factory()->create();
    server($org, 1, 2048);
    server($org, 8, 16384);

    // Two servers → Starter, regardless of the XL-sized second server.
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    expect($desired->planKey)->toBe('starter');
    expect($this->creator->buildPriceList($desired))->toBe([
        ['price' => 'price_starter', 'quantity' => 1],
    ]);
});

test('the plan line tracks the count ceiling', function () {
    $org = Organization::factory()->create();
    foreach (range(1, 4) as $i) {
        server($org, 1, 2048);
    }

    // Four servers → Pro.
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());

    expect($this->creator->buildPriceList($desired))->toBe([
        ['price' => 'price_pro', 'quantity' => 1],
    ]);
});

test('a free plan with managed products emits only the managed lines', function () {
    $desired = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        serverlessCount: 3,
        serverlessUnitCents: 200,
    );

    expect($this->creator->buildPriceList($desired))->toBe([
        ['price' => 'price_serverless', 'quantity' => 3],
    ]);
});

test('yearly interval uses the yearly plan price', function () {
    $org = Organization::factory()->create();
    foreach (range(1, 4) as $i) {
        server($org, 1, 2048);
    }

    // Four servers → Pro, yearly.
    $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());
    $items = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_YEAR);

    expect($items)->toBe([
        ['price' => 'price_pro_y', 'quantity' => 1],
    ]);

    // Critical: no mixed intervals. Stripe Checkout rejects subscriptions
    // that mix monthly + yearly line items.
    foreach ($items as $item) {
        $this->assertNotSame('price_pro', $item['price']);
    }
});

test('serverless functions add an interval aware line item', function () {
    $desired = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        serverlessCount: 3,
        serverlessUnitCents: 200,
    );

    $monthly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_MONTH);
    $this->assertContainsEquals(['price' => 'price_serverless', 'quantity' => 3], $monthly);

    $yearly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_YEAR);
    $this->assertContainsEquals(['price' => 'price_serverless_y', 'quantity' => 3], $yearly);
});

test('cloud and edge sites add interval aware line items', function () {
    $desired = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
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

test('edge usage adds a metered line item on the monthly interval only', function () {
    $desired = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 1,
        edgeUnitCents: 200,
        edgeUsageSubtotalCents: 350,
    );

    $monthly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_MONTH);
    $this->assertContainsEquals(['price' => 'price_edge_usage', 'quantity' => 350], $monthly);

    $yearly = $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_YEAR);
    foreach ($yearly as $item) {
        $this->assertNotSame('price_edge_usage', $item['price']);
    }
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
