<?php

namespace Tests\Unit\Services\Billing;

use App\Enums\ServerTier;
use App\Services\Billing\DesiredBillingState;
use PHPUnit\Framework\TestCase;

class DesiredBillingStateTest extends TestCase
{
    private const TIER_PRICES = ['xs' => 200, 's' => 500, 'm' => 1000, 'l' => 2000, 'xl' => 4000];

    private const BASE = 2500;

    private const CREDIT = 1000;

    public function test_empty_fleet_bills_only_the_base(): void
    {
        $state = DesiredBillingState::fromCounts(
            tierQuantities: [],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(0, $state->serverCount());
        $this->assertSame(0, $state->serverSubtotalCents);
        $this->assertSame(0, $state->appliedCreditCents);
        $this->assertSame(2500, $state->monthlyTotalCents);
    }

    public function test_one_m_server_is_fully_absorbed_by_credit(): void
    {
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['m' => 1],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(1, $state->serverCount());
        $this->assertSame(1000, $state->serverSubtotalCents);
        $this->assertSame(1000, $state->appliedCreditCents);
        $this->assertSame(2500, $state->monthlyTotalCents);
    }

    public function test_one_xs_server_credit_caps_at_server_subtotal(): void
    {
        // $2 server + $25 base; $10 credit can only absorb $2 — no negative bill.
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['xs' => 1],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(200, $state->serverSubtotalCents);
        $this->assertSame(200, $state->appliedCreditCents);
        $this->assertSame(2500, $state->monthlyTotalCents);
    }

    public function test_mixed_fleet_computes_per_tier_totals(): void
    {
        // 2 M ($10 ea) + 1 L ($20) + 1 XS ($2) = $42 subtotal
        // $25 base - $10 credit + $42 = $57 = 5700 cents
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['m' => 2, 'l' => 1, 'xs' => 1],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(4, $state->serverCount());
        $this->assertSame(4200, $state->serverSubtotalCents);
        $this->assertSame(1000, $state->appliedCreditCents);
        $this->assertSame(5700, $state->monthlyTotalCents);
    }

    public function test_xl_only_customer_still_gets_full_credit(): void
    {
        // 1 XL ($40) + $25 base - $10 credit = $55
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['xl' => 1],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(1, $state->serverCount());
        $this->assertSame(4000, $state->serverSubtotalCents);
        $this->assertSame(1000, $state->appliedCreditCents);
        $this->assertSame(5500, $state->monthlyTotalCents);
    }

    public function test_unknown_tier_keys_are_ignored(): void
    {
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['xs' => 1, 'mythical_tier' => 99],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(1, $state->serverCount());
        $this->assertSame(200, $state->serverSubtotalCents);
    }

    public function test_negative_quantities_are_clamped_to_zero(): void
    {
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['xs' => -5],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(0, $state->serverCount());
        $this->assertSame(0, $state->serverSubtotalCents);
    }

    public function test_quantity_for_returns_zero_for_unbought_tiers(): void
    {
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['m' => 3],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $this->assertSame(3, $state->quantityFor(ServerTier::M));
        $this->assertSame(0, $state->quantityFor(ServerTier::XS));
        $this->assertSame(0, $state->quantityFor(ServerTier::XL));
    }

    public function test_serverless_functions_add_a_flat_per_function_subtotal(): void
    {
        // 3 functions × $2 + $15 base = $21 = 2100 cents
        $state = DesiredBillingState::fromCounts(
            tierQuantities: [],
            baseCents: 1500,
            creditCents: 0,
            tierPricesCents: self::TIER_PRICES,
            serverlessCount: 3,
            serverlessUnitCents: 200,
        );

        $this->assertSame(3, $state->serverlessCount);
        $this->assertSame(600, $state->serverlessSubtotalCents);
        $this->assertSame(2100, $state->monthlyTotalCents);
    }

    public function test_serverless_and_servers_combine_in_the_total(): void
    {
        // 2 M servers ($20) + 4 functions ($8) + $15 base = $43 = 4300 cents
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['m' => 2],
            baseCents: 1500,
            creditCents: 0,
            tierPricesCents: self::TIER_PRICES,
            serverlessCount: 4,
            serverlessUnitCents: 200,
        );

        $this->assertSame(2000, $state->serverSubtotalCents);
        $this->assertSame(800, $state->serverlessSubtotalCents);
        $this->assertSame(4300, $state->monthlyTotalCents);
    }

    public function test_negative_serverless_count_is_clamped(): void
    {
        $state = DesiredBillingState::fromCounts(
            tierQuantities: [],
            baseCents: 1500,
            creditCents: 0,
            tierPricesCents: self::TIER_PRICES,
            serverlessCount: -5,
            serverlessUnitCents: 200,
        );

        $this->assertSame(0, $state->serverlessCount);
        $this->assertSame(0, $state->serverlessSubtotalCents);
    }

    public function test_to_array_round_trips_for_queue_payloads(): void
    {
        $state = DesiredBillingState::fromCounts(
            tierQuantities: ['s' => 2, 'l' => 1],
            baseCents: self::BASE,
            creditCents: self::CREDIT,
            tierPricesCents: self::TIER_PRICES,
        );

        $array = $state->toArray();

        $this->assertSame(
            ['xs' => 0, 's' => 2, 'm' => 0, 'l' => 1, 'xl' => 0],
            $array['tier_quantities'],
        );
        $this->assertSame(2500, $array['base_cents']);
        $this->assertSame(3000, $array['server_subtotal_cents']);
        $this->assertSame(1000, $array['applied_credit_cents']);
        $this->assertSame(4500, $array['monthly_total_cents']);
    }
}
