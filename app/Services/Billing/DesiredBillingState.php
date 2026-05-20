<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;

/**
 * Snapshot of what an organization *should* be billed this cycle, derived
 * purely from its current server fleet. The sync layer reconciles a Stripe
 * subscription against this shape.
 *
 * Always pre-discount and pre-tax; expressed in cents and tier-keyed
 * quantities so it survives JSON-round-trips through queue payloads.
 */
class DesiredBillingState
{
    /**
     * @param  array<string, int>  $tierQuantities  Keys mirror ServerTier->value (xs/s/m/l/xl).
     */
    private function __construct(
        public readonly array $tierQuantities,
        public readonly int $baseCents,
        public readonly int $serverSubtotalCents,
        public readonly int $appliedCreditCents,
        public readonly int $monthlyTotalCents,
    ) {}

    /**
     * @param  array<string, int>  $tierQuantities
     */
    public static function fromCounts(array $tierQuantities, int $baseCents, int $creditCents, array $tierPricesCents): self
    {
        $normalized = [];
        foreach (ServerTier::ordered() as $tier) {
            $normalized[$tier->value] = max(0, (int) ($tierQuantities[$tier->value] ?? 0));
        }

        $subtotal = 0;
        foreach ($normalized as $tierKey => $qty) {
            $subtotal += $qty * (int) ($tierPricesCents[$tierKey] ?? 0);
        }

        // Flat credit never produces a negative bill; it absorbs the server
        // subtotal first, then leaves the base intact.
        $applied = min($creditCents, $subtotal);
        $monthly = max(0, $baseCents + $subtotal - $applied);

        return new self(
            tierQuantities: $normalized,
            baseCents: $baseCents,
            serverSubtotalCents: $subtotal,
            appliedCreditCents: $applied,
            monthlyTotalCents: $monthly,
        );
    }

    public function serverCount(): int
    {
        return array_sum($this->tierQuantities);
    }

    public function quantityFor(ServerTier $tier): int
    {
        return $this->tierQuantities[$tier->value] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tier_quantities' => $this->tierQuantities,
            'base_cents' => $this->baseCents,
            'server_subtotal_cents' => $this->serverSubtotalCents,
            'applied_credit_cents' => $this->appliedCreditCents,
            'monthly_total_cents' => $this->monthlyTotalCents,
        ];
    }
}
