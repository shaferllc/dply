<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;

/**
 * Snapshot of what an organization *should* be billed this cycle, derived
 * purely from its current fleet. The sync layer reconciles a Stripe
 * subscription against this shape.
 *
 * Billing model (post size-tier migration):
 * - A single flat **plan** chosen by billable BYO server *count* (Free /
 *   Starter / Pro / Business). Server size no longer affects the dply fee.
 * - **Managed products** billed a la carte per unit on top of the plan,
 *   regardless of which plan (including Free): serverless functions, dply
 *   Cloud apps, dply Edge sites.
 * - **dply Cloud resources** — metered cost-plus for the DigitalOcean
 *   containers, workers, databases, and buckets backing Cloud apps. Billed on
 *   top of the flat per-app platform fee, not plan-eligible.
 * - **Edge delivery usage** — metered pass-through on top, not plan-eligible.
 *
 * `tierQuantities` is retained as a *display-only* size breakdown (the billing
 * dashboard still shows which sizes a fleet runs); it no longer drives price.
 *
 * Always pre-tax; expressed in cents and tier-keyed quantities so it survives
 * JSON round-trips through queue payloads.
 */
class DesiredBillingState
{
    /**
     * @param  array<string, mixed> $tierQuantities  Display-only size breakdown (xs/s/m/l/xl).
     * @param  array<string, mixed> $edgeUsageEstimate
     */
    private function __construct(
        public readonly string $planKey,
        public readonly string $planLabel,
        public readonly int $planPriceCents,
        public readonly array $tierQuantities,
        public readonly int $serverlessCount,
        public readonly int $serverlessSubtotalCents,
        public readonly int $serverlessUsageSubtotalCents,
        public readonly int $managedServerCount,
        public readonly int $managedServerSubtotalCents,
        public readonly int $cloudCount,
        public readonly int $cloudSubtotalCents,
        public readonly int $cloudResourceSubtotalCents,
        public readonly int $edgeCount,
        public readonly int $edgeSubtotalCents,
        public readonly int $edgeUsageSubtotalCents,
        public readonly array $edgeUsageEstimate,
        public readonly int $realtimeCount,
        public readonly int $realtimeSubtotalCents,
        /** @var array<string, int> Active managed-realtime app counts keyed by tier slug. */
        public readonly array $realtimeTierQuantities,
        public readonly int $monthlyTotalCents,
        // --- Back-compat shims for consumers not yet migrated off the old
        // size-tier shape (billing dashboard, analytics, forecast, snapshot).
        // baseCents is always 0 under the plan model; serverSubtotalCents
        // mirrors the flat plan fee so "server fees" rows still total right.
        public readonly int $baseCents = 0,
        public readonly int $serverSubtotalCents = 0,
        public readonly int $appliedCreditCents = 0,
        // dply Logs ingest overage — metered pass-through on top, not
        // plan-eligible. 0 until billing is enabled + a plan carries a rate (PR C).
        public readonly int $serverLogUsageSubtotalCents = 0,
        /** @var array<string, mixed> */
        public readonly array $serverLogUsageEstimate = [],
    ) {}

    /**
     * Build a state from a resolved plan plus managed-product usage.
     *
     * @param  array{key: string, label: string, price_cents: int, max_servers: ?int}  $plan
     * @param  array<string, mixed> $tierQuantities  Display-only size breakdown.
     * @param  array<string, mixed> $edgeUsageEstimate
     * @param  array<string, mixed> $realtimeTierQuantities
     */
    public static function fromPlanAndUsage(
        array $plan,
        array $tierQuantities = [],
        int $serverlessCount = 0,
        int $serverlessUnitCents = 0,
        int $serverlessUsageSubtotalCents = 0,
        int $managedServerCount = 0,
        int $managedServerSubtotalCents = 0,
        int $cloudCount = 0,
        int $cloudUnitCents = 0,
        int $cloudResourceSubtotalCents = 0,
        int $edgeCount = 0,
        int $edgeUnitCents = 0,
        int $edgeUsageSubtotalCents = 0,
        array $edgeUsageEstimate = [],
        // Legacy flat realtime inputs — kept for back-compat. Prefer
        // $realtimeTierQuantities, which prices each app by its tier.
        int $realtimeCount = 0,
        int $realtimeUnitCents = 0,
        array $realtimeTierQuantities = [],
        int $serverLogUsageSubtotalCents = 0,
        array $serverLogUsageEstimate = [],
    ): self {
        $normalized = [];
        foreach (ServerTier::ordered() as $tier) {
            $normalized[$tier->value] = max(0, (int) ($tierQuantities[$tier->value] ?? 0));
        }

        $planPriceCents = max(0, (int) ($plan['price_cents']));

        $serverlessCount = max(0, $serverlessCount);
        $serverlessSubtotal = $serverlessCount * max(0, $serverlessUnitCents);
        $serverlessUsageSubtotalCents = max(0, $serverlessUsageSubtotalCents);

        $managedServerCount = max(0, $managedServerCount);
        $managedServerSubtotalCents = max(0, $managedServerSubtotalCents);

        $cloudCount = max(0, $cloudCount);
        $cloudSubtotal = $cloudCount * max(0, $cloudUnitCents);
        $cloudResourceSubtotalCents = max(0, $cloudResourceSubtotalCents);

        $edgeCount = max(0, $edgeCount);
        $edgeSubtotal = $edgeCount * max(0, $edgeUnitCents);

        $edgeUsageSubtotalCents = max(0, $edgeUsageSubtotalCents);

        $serverLogUsageSubtotalCents = max(0, $serverLogUsageSubtotalCents);

        // Realtime: prefer per-tier quantities priced from config('realtime.tiers');
        // fall back to the legacy flat count×unit for any caller not yet migrated
        // (a flat count is attributed to the default tier for display).
        $realtimeTiers = (array) config('realtime.tiers', []);
        $realtimeTierNormalized = [];
        if ($realtimeTierQuantities !== []) {
            $realtimeSubtotal = 0;
            foreach ($realtimeTierQuantities as $slug => $qty) {
                $qty = max(0, (int) $qty);
                if ($qty === 0) {
                    continue;
                }
                $realtimeTierNormalized[(string) $slug] = $qty;
                $realtimeSubtotal += $qty * (int) ($realtimeTiers[(string) $slug]['price_cents'] ?? 0);
            }
            $realtimeCount = array_sum($realtimeTierNormalized);
        } else {
            $realtimeCount = max(0, $realtimeCount);
            $realtimeSubtotal = $realtimeCount * max(0, $realtimeUnitCents);
            if ($realtimeCount > 0) {
                $realtimeTierNormalized[(string) config('realtime.default_tier', 'starter')] = $realtimeCount;
            }
        }

        $monthly = $planPriceCents
            + $serverlessSubtotal
            + $serverlessUsageSubtotalCents
            + $managedServerSubtotalCents
            + $cloudSubtotal
            + $cloudResourceSubtotalCents
            + $edgeSubtotal
            + $edgeUsageSubtotalCents
            + $serverLogUsageSubtotalCents
            + $realtimeSubtotal;

        return new self(
            planKey: $plan['key'],
            planLabel: $plan['label'],
            planPriceCents: $planPriceCents,
            tierQuantities: $normalized,
            serverlessCount: $serverlessCount,
            serverlessSubtotalCents: $serverlessSubtotal,
            serverlessUsageSubtotalCents: $serverlessUsageSubtotalCents,
            managedServerCount: $managedServerCount,
            managedServerSubtotalCents: $managedServerSubtotalCents,
            cloudCount: $cloudCount,
            cloudSubtotalCents: $cloudSubtotal,
            cloudResourceSubtotalCents: $cloudResourceSubtotalCents,
            edgeCount: $edgeCount,
            edgeSubtotalCents: $edgeSubtotal,
            edgeUsageSubtotalCents: $edgeUsageSubtotalCents,
            edgeUsageEstimate: $edgeUsageEstimate,
            realtimeCount: $realtimeCount,
            realtimeSubtotalCents: $realtimeSubtotal,
            realtimeTierQuantities: $realtimeTierNormalized,
            monthlyTotalCents: $monthly,
            baseCents: 0,
            serverSubtotalCents: $planPriceCents,
            appliedCreditCents: 0,
            serverLogUsageSubtotalCents: $serverLogUsageSubtotalCents,
            serverLogUsageEstimate: $serverLogUsageEstimate,
        );
    }

    /**
     * Total billable BYO server count (drives plan selection upstream).
     */
    public function serverCount(): int
    {
        return array_sum($this->tierQuantities);
    }

    public function quantityFor(ServerTier $tier): int
    {
        return $this->tierQuantities[$tier->value] ?? 0;
    }

    /**
     * Combined a-la-carte managed-product subtotal — flat per-unit fees plus
     * metered Cloud provider resources (excludes Edge delivery usage).
     */
    public function managedSubtotalCents(): int
    {
        return $this->serverlessSubtotalCents
            + $this->managedServerSubtotalCents
            + $this->cloudSubtotalCents
            + $this->cloudResourceSubtotalCents
            + $this->edgeSubtotalCents
            + $this->realtimeSubtotalCents;
    }

    /**
     * True when the org owes nothing this cycle — a free-plan org with no
     * managed products and no Edge usage. Drives "no subscription / never
     * paused" lifecycle decisions.
     */
    public function isFree(): bool
    {
        return $this->monthlyTotalCents <= 0;
    }

    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_key' => $this->planKey,
            'plan_label' => $this->planLabel,
            'plan_price_cents' => $this->planPriceCents,
            'server_count' => $this->serverCount(),
            'tier_quantities' => $this->tierQuantities,
            'serverless_count' => $this->serverlessCount,
            'serverless_subtotal_cents' => $this->serverlessSubtotalCents,
            'serverless_usage_subtotal_cents' => $this->serverlessUsageSubtotalCents,
            'managed_server_count' => $this->managedServerCount,
            'managed_server_subtotal_cents' => $this->managedServerSubtotalCents,
            'cloud_count' => $this->cloudCount,
            'cloud_subtotal_cents' => $this->cloudSubtotalCents,
            'cloud_resource_subtotal_cents' => $this->cloudResourceSubtotalCents,
            'edge_count' => $this->edgeCount,
            'edge_subtotal_cents' => $this->edgeSubtotalCents,
            'edge_usage_subtotal_cents' => $this->edgeUsageSubtotalCents,
            'edge_usage_estimate' => $this->edgeUsageEstimate,
            'server_log_usage_subtotal_cents' => $this->serverLogUsageSubtotalCents,
            'server_log_usage_estimate' => $this->serverLogUsageEstimate,
            'realtime_count' => $this->realtimeCount,
            'realtime_subtotal_cents' => $this->realtimeSubtotalCents,
            'realtime_tier_quantities' => $this->realtimeTierQuantities,
            'monthly_total_cents' => $this->monthlyTotalCents,
            // Back-compat keys (snapshots/forecast read these today).
            'base_cents' => $this->baseCents,
            'server_subtotal_cents' => $this->serverSubtotalCents,
            'applied_credit_cents' => $this->appliedCreditCents,
        ];
    }
}
