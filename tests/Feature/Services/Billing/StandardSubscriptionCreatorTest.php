<?php

namespace Tests\Feature\Services\Billing;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\StandardSubscriptionCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * Pure-logic tests for the price-list construction in StandardSubscriptionCreator.
 * The actual Cashier `->create($pm)` call requires a live Stripe customer and is
 * exercised by manual QA against test-mode Stripe; this suite verifies the inputs
 * the creator hands to Cashier.
 */
class StandardSubscriptionCreatorTest extends TestCase
{
    use RefreshDatabase;

    private StandardSubscriptionCreator $creator;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests create servers via factory with `created_at = now()`, which the
        // default 1-day grace window would exclude. Zero it for the suite — a
        // dedicated computer test exercises the threshold separately.
        Config::set('subscription.standard.min_billable_age_days', 0);
        Config::set('subscription.standard.stripe', [
            'base_monthly' => 'price_base_monthly',
            'base_yearly' => 'price_base_yearly',
            'serverless' => 'price_serverless',
            'serverless_yearly' => 'price_serverless_y',
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
    }

    public function test_throws_when_base_price_is_not_configured(): void
    {
        Config::set('subscription.standard.stripe.base_monthly', '');
        $org = Organization::factory()->create();
        $desired = app(OrganizationBillingStateComputer::class)->compute($org);

        $this->expectException(RuntimeException::class);
        $this->creator->buildPriceList($desired, StandardSubscriptionCreator::INTERVAL_MONTH);
    }

    public function test_builds_minimum_line_items_for_empty_fleet(): void
    {
        $org = Organization::factory()->create();
        $desired = app(OrganizationBillingStateComputer::class)->compute($org);

        $items = $this->creator->buildPriceList($desired);

        $this->assertSame([
            ['price' => 'price_base_monthly', 'quantity' => 1],
        ], $items);
    }

    public function test_builds_a_line_item_per_non_empty_tier(): void
    {
        $org = Organization::factory()->create();
        $this->server($org, 4, 8192);   // M
        $this->server($org, 4, 8192);   // M
        $this->server($org, 8, 16384);  // L
        $this->server($org, 1, 2048);   // XS

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
    }

    public function test_skips_tiers_whose_stripe_prices_are_missing(): void
    {
        Config::set('subscription.standard.stripe.tiers.m', '');
        $org = Organization::factory()->create();
        $this->server($org, 4, 8192);  // M — but tier price is empty

        $desired = app(OrganizationBillingStateComputer::class)->compute($org->fresh());
        $items = $this->creator->buildPriceList($desired);

        foreach ($items as $item) {
            $this->assertNotSame('price_tier_m', $item['price']);
        }
    }

    public function test_yearly_interval_uses_yearly_base_price_and_yearly_tiers(): void
    {
        $org = Organization::factory()->create();
        $this->server($org, 4, 8192);   // M
        $this->server($org, 8, 16384);  // L

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
    }

    public function test_tier_price_ids_for_interval_returns_correct_set(): void
    {
        $monthlyIds = $this->creator->tierPriceIdsForInterval(StandardSubscriptionCreator::INTERVAL_MONTH);
        $yearlyIds = $this->creator->tierPriceIdsForInterval(StandardSubscriptionCreator::INTERVAL_YEAR);

        $this->assertSame('price_tier_m', $monthlyIds['m']);
        $this->assertSame('price_tier_m_y', $yearlyIds['m']);
    }

    public function test_serverless_functions_add_an_interval_aware_line_item(): void
    {
        $desired = \App\Services\Billing\DesiredBillingState::fromCounts(
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
    }

    public function test_no_serverless_line_item_when_count_is_zero(): void
    {
        $org = Organization::factory()->create();
        $items = $this->creator->buildPriceList(
            app(OrganizationBillingStateComputer::class)->compute($org)
        );

        foreach ($items as $item) {
            $this->assertStringNotContainsString('serverless', $item['price']);
        }
    }

    private function server(Organization $org, int $cpu, int $memMb): Server
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
}
