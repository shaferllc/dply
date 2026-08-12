<?php

namespace App\Modules\Billing\Services;

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
 * Always pre-tax; expressed in cents and plain counts so it survives JSON
 * round-trips through queue payloads.
 */
class DesiredBillingState
{
    /**
     * @param  array<string, mixed>  $edgeUsageEstimate
     */
    private function __construct(
        public readonly string $planKey,
        public readonly string $planLabel,
        public readonly int $planPriceCents,
        /** Billable BYO servers. Was an xs/s/m/l/xl breakdown that only ever got summed. */
        public readonly int $billableServerCount,
        public readonly int $serverlessCount,
        public readonly int $serverlessSubtotalCents,
        public readonly int $serverlessUsageSubtotalCents,
        public readonly int $managedServerCount,
        public readonly int $managedServerSubtotalCents,
        public readonly int $cloudCount,
        public readonly int $cloudSubtotalCents,
        public readonly int $cloudResourceSubtotalCents,
        public readonly int $edgeCount,
        /** Worker-native SSR sites included in edgeCount (billed at edge_ssr_cents). */
        public readonly int $edgeSsrCount,
        public readonly int $edgeSubtotalCents,
        public readonly int $edgeUsageSubtotalCents,
        public readonly array $edgeUsageEstimate,
        public readonly int $realtimeCount,
        public readonly int $realtimeSubtotalCents,
        /** @var array<string, int> Active managed-realtime app counts keyed by tier slug. */
        public readonly array $realtimeTierQuantities,
        public readonly int $lookoutCount,
        public readonly int $lookoutSubtotalCents,
        /** @var array<string, int> Billable managed-Lookout project counts keyed by tier slug. */
        public readonly array $lookoutTierQuantities,
        public readonly int $queueCount,
        public readonly int $queueSubtotalCents,
        /**
         * Billable dply Queue namespace counts keyed by capacity-tier slug.
         * Serverless-attached namespaces are already excluded upstream — they
         * ride free (docs/adr/managed-services-tier.md, decision 4).
         *
         * @var array<string, int>
         */
        public readonly array $queueTierQuantities,
        /** @var list<string> Ids of the namespaces counted above, for the flip diff. */
        public readonly array $queueBillableNamespaceIds,
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
     * @param  array<string, mixed>  $edgeUsageEstimate
     * @param  array<string, mixed>  $realtimeTierQuantities
     * @param  array<string, mixed>  $queueTierQuantities
     * @param  list<string>  $queueBillableNamespaceIds
     */
    public static function fromPlanAndUsage(
        array $plan,
        int $billableServerCount = 0,
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
        int $edgeSsrCount = 0,
        int $edgeSsrUnitCents = 0,
        int $edgeUsageSubtotalCents = 0,
        array $edgeUsageEstimate = [],
        // Legacy flat realtime inputs — kept for back-compat. Prefer
        // $realtimeTierQuantities, which prices each app by its tier.
        int $realtimeCount = 0,
        int $realtimeUnitCents = 0,
        array $realtimeTierQuantities = [],
        array $lookoutTierQuantities = [],
        array $queueTierQuantities = [],
        array $queueBillableNamespaceIds = [],
        int $serverLogUsageSubtotalCents = 0,
        array $serverLogUsageEstimate = [],
    ): self {
        $billableServerCount = max(0, $billableServerCount);

        $planPriceCents = max(0, (int) ($plan['price_cents'] ?? 0));

        $serverlessCount = max(0, $serverlessCount);
        $serverlessSubtotal = $serverlessCount * max(0, $serverlessUnitCents);
        $serverlessUsageSubtotalCents = max(0, $serverlessUsageSubtotalCents);

        $managedServerCount = max(0, $managedServerCount);
        $managedServerSubtotalCents = max(0, $managedServerSubtotalCents);

        $cloudCount = max(0, $cloudCount);
        $cloudSubtotal = $cloudCount * max(0, $cloudUnitCents);
        $cloudResourceSubtotalCents = max(0, $cloudResourceSubtotalCents);

        $edgeCount = max(0, $edgeCount);
        $edgeSsrCount = min($edgeCount, max(0, $edgeSsrCount));
        $edgeBaseCount = $edgeCount - $edgeSsrCount;
        $edgeSubtotal = ($edgeBaseCount * max(0, $edgeUnitCents))
            + ($edgeSsrCount * max(0, $edgeSsrUnitCents));

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

        // Managed Lookout: one line per project tier, priced from
        // config('lookout.tiers'). The computer already excludes the org's free
        // project(s) and zeroes everything when billing is disabled.
        $lookoutTiers = (array) config('lookout.tiers', []);
        $lookoutTierNormalized = [];
        $lookoutSubtotal = 0;
        foreach ($lookoutTierQuantities as $slug => $qty) {
            $qty = max(0, (int) $qty);
            if ($qty === 0) {
                continue;
            }
            $lookoutTierNormalized[(string) $slug] = $qty;
            $lookoutSubtotal += $qty * (int) ($lookoutTiers[(string) $slug]['price_cents'] ?? 0);
        }
        $lookoutCount = array_sum($lookoutTierNormalized);

        // dply Queue: one line per namespace capacity tier, priced from
        // config('queue_service.tiers'). The computer has already dropped
        // Serverless-attached namespaces (they ride free) and zeroes everything
        // when queue_service.billing.enabled is off.
        $queueTiers = (array) config('queue_service.tiers', []);
        $queueTierNormalized = [];
        $queueSubtotal = 0;
        foreach ($queueTierQuantities as $slug => $qty) {
            $qty = max(0, (int) $qty);
            if ($qty === 0) {
                continue;
            }
            $queueTierNormalized[(string) $slug] = $qty;
            $queueSubtotal += $qty * (int) ($queueTiers[(string) $slug]['price_cents'] ?? 0);
        }
        $queueCount = array_sum($queueTierNormalized);

        $monthly = $planPriceCents
            + $serverlessSubtotal
            + $serverlessUsageSubtotalCents
            + $managedServerSubtotalCents
            + $cloudSubtotal
            + $cloudResourceSubtotalCents
            + $edgeSubtotal
            + $edgeUsageSubtotalCents
            + $serverLogUsageSubtotalCents
            + $realtimeSubtotal
            + $lookoutSubtotal
            + $queueSubtotal;

        return new self(
            planKey: $plan['key'],
            planLabel: $plan['label'],
            planPriceCents: $planPriceCents,
            billableServerCount: $billableServerCount,
            serverlessCount: $serverlessCount,
            serverlessSubtotalCents: $serverlessSubtotal,
            serverlessUsageSubtotalCents: $serverlessUsageSubtotalCents,
            managedServerCount: $managedServerCount,
            managedServerSubtotalCents: $managedServerSubtotalCents,
            cloudCount: $cloudCount,
            cloudSubtotalCents: $cloudSubtotal,
            cloudResourceSubtotalCents: $cloudResourceSubtotalCents,
            edgeCount: $edgeCount,
            edgeSsrCount: $edgeSsrCount,
            edgeSubtotalCents: $edgeSubtotal,
            edgeUsageSubtotalCents: $edgeUsageSubtotalCents,
            edgeUsageEstimate: $edgeUsageEstimate,
            realtimeCount: $realtimeCount,
            realtimeSubtotalCents: $realtimeSubtotal,
            realtimeTierQuantities: $realtimeTierNormalized,
            lookoutCount: $lookoutCount,
            lookoutSubtotalCents: $lookoutSubtotal,
            lookoutTierQuantities: $lookoutTierNormalized,
            queueCount: $queueCount,
            queueSubtotalCents: $queueSubtotal,
            queueTierQuantities: $queueTierNormalized,
            queueBillableNamespaceIds: array_values(array_map(strval(...), $queueBillableNamespaceIds)),
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
        return $this->billableServerCount;
    }

    /** Static / hybrid Edge sites (Stripe `edge` line quantity). */
    public function edgeBaseCount(): int
    {
        return max(0, $this->edgeCount - $this->edgeSsrCount);
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
            + $this->realtimeSubtotalCents
            + $this->lookoutSubtotalCents
            + $this->queueSubtotalCents;
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
            'serverless_count' => $this->serverlessCount,
            'serverless_subtotal_cents' => $this->serverlessSubtotalCents,
            'serverless_usage_subtotal_cents' => $this->serverlessUsageSubtotalCents,
            'managed_server_count' => $this->managedServerCount,
            'managed_server_subtotal_cents' => $this->managedServerSubtotalCents,
            'cloud_count' => $this->cloudCount,
            'cloud_subtotal_cents' => $this->cloudSubtotalCents,
            'cloud_resource_subtotal_cents' => $this->cloudResourceSubtotalCents,
            'edge_count' => $this->edgeCount,
            'edge_ssr_count' => $this->edgeSsrCount,
            'edge_subtotal_cents' => $this->edgeSubtotalCents,
            'edge_usage_subtotal_cents' => $this->edgeUsageSubtotalCents,
            'edge_usage_estimate' => $this->edgeUsageEstimate,
            'server_log_usage_subtotal_cents' => $this->serverLogUsageSubtotalCents,
            'server_log_usage_estimate' => $this->serverLogUsageEstimate,
            'realtime_count' => $this->realtimeCount,
            'realtime_subtotal_cents' => $this->realtimeSubtotalCents,
            'realtime_tier_quantities' => $this->realtimeTierQuantities,
            'lookout_count' => $this->lookoutCount,
            'lookout_subtotal_cents' => $this->lookoutSubtotalCents,
            'lookout_tier_quantities' => $this->lookoutTierQuantities,
            'queue_count' => $this->queueCount,
            'queue_subtotal_cents' => $this->queueSubtotalCents,
            'queue_tier_quantities' => $this->queueTierQuantities,
            // Which namespaces were billed, not just how many. This is what the
            // billability-flip notifier diffs against, and it is the audit trail
            // that live attribution otherwise lacks: derived billability can say
            // "free today" but only the snapshot records what we charged for in
            // a given cycle. See docs/adr/managed-services-tier.md, decision 7.
            'queue_billable_namespace_ids' => $this->queueBillableNamespaceIds,
            'monthly_total_cents' => $this->monthlyTotalCents,
            // Back-compat keys (snapshots/forecast read these today).
            'base_cents' => $this->baseCents,
            'server_subtotal_cents' => $this->serverSubtotalCents,
            'applied_credit_cents' => $this->appliedCreditCents,
        ];
    }
}
