<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;

/**
 * Snapshot of what an organization *should* be billed this cycle, derived
 * purely from its current fleet. The sync layer reconciles a Stripe
 * subscription against this shape.
 *
 * Billable units are of four kinds:
 * - spec-tiered BYO servers (XS–XL) — customer VMs you SSH into
 * - serverless functions — flat per code action
 * - dply Cloud apps — flat per live container site on dply-owned infra
 * - dply Edge sites — flat per live static/SSG site on dply-owned infra
 * - dply Edge delivery usage — metered pass-through (when enabled) on top of
 *   the flat per-site platform fee; not absorbed by included credit
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
        public readonly int $serverlessCount,
        public readonly int $serverlessSubtotalCents,
        public readonly int $cloudCount,
        public readonly int $cloudSubtotalCents,
        public readonly int $edgeCount,
        public readonly int $edgeSubtotalCents,
        public readonly int $edgeUsageSubtotalCents,
        /** @var array<string, mixed> */
        public readonly array $edgeUsageEstimate,
        public readonly int $appliedCreditCents,
        public readonly int $monthlyTotalCents,
    ) {}

    /**
     * @param  array<string, int>  $tierQuantities
     * @param  array<string, int>  $tierPricesCents
     */
    public static function fromCounts(
        array $tierQuantities,
        int $baseCents,
        int $creditCents,
        array $tierPricesCents,
        int $serverlessCount = 0,
        int $serverlessUnitCents = 0,
        int $cloudCount = 0,
        int $cloudUnitCents = 0,
        int $edgeCount = 0,
        int $edgeUnitCents = 0,
        int $edgeUsageSubtotalCents = 0,
        array $edgeUsageEstimate = [],
    ): self {
        $normalized = [];
        foreach (ServerTier::ordered() as $tier) {
            $normalized[$tier->value] = max(0, (int) ($tierQuantities[$tier->value] ?? 0));
        }

        $subtotal = 0;
        foreach ($normalized as $tierKey => $qty) {
            $subtotal += $qty * (int) ($tierPricesCents[$tierKey] ?? 0);
        }

        $serverlessCount = max(0, $serverlessCount);
        $serverlessSubtotal = $serverlessCount * max(0, $serverlessUnitCents);

        $cloudCount = max(0, $cloudCount);
        $cloudSubtotal = $cloudCount * max(0, $cloudUnitCents);

        $edgeCount = max(0, $edgeCount);
        $edgeSubtotal = $edgeCount * max(0, $edgeUnitCents);
        $edgeUsageSubtotalCents = max(0, $edgeUsageSubtotalCents);

        $managedSubtotal = $serverlessSubtotal + $cloudSubtotal + $edgeSubtotal;

        // Flat credit never produces a negative bill; it absorbs the per-unit
        // subtotal (servers + managed products) first, leaving the base intact.
        // Edge delivery usage is pass-through and is not credit-eligible.
        $applied = min($creditCents, $subtotal + $managedSubtotal);
        $monthly = max(0, $baseCents + $subtotal + $managedSubtotal - $applied + $edgeUsageSubtotalCents);

        return new self(
            tierQuantities: $normalized,
            baseCents: $baseCents,
            serverSubtotalCents: $subtotal,
            serverlessCount: $serverlessCount,
            serverlessSubtotalCents: $serverlessSubtotal,
            cloudCount: $cloudCount,
            cloudSubtotalCents: $cloudSubtotal,
            edgeCount: $edgeCount,
            edgeSubtotalCents: $edgeSubtotal,
            edgeUsageSubtotalCents: $edgeUsageSubtotalCents,
            edgeUsageEstimate: $edgeUsageEstimate,
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
            'serverless_count' => $this->serverlessCount,
            'serverless_subtotal_cents' => $this->serverlessSubtotalCents,
            'cloud_count' => $this->cloudCount,
            'cloud_subtotal_cents' => $this->cloudSubtotalCents,
            'edge_count' => $this->edgeCount,
            'edge_subtotal_cents' => $this->edgeSubtotalCents,
            'edge_usage_subtotal_cents' => $this->edgeUsageSubtotalCents,
            'edge_usage_estimate' => $this->edgeUsageEstimate,
            'applied_credit_cents' => $this->appliedCreditCents,
            'monthly_total_cents' => $this->monthlyTotalCents,
        ];
    }
}
