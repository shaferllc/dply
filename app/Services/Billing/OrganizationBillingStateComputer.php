<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;
use App\Models\Organization;
use App\Models\Server;

/**
 * Builds a {@see DesiredBillingState} for an organization by scanning its
 * currently *billable* servers (status = ready, older than the configured
 * minimum age) and classifying each into a tier via the server's own
 * billingTier() accessor.
 *
 * Two filters apply, on top of fleet-membership:
 *
 * - **Status:** only `ready` servers count. Provisioning, error, pending,
 *   and disconnected states are excluded so the customer isn't billed for
 *   transient provisioning artifacts.
 * - **Age:** servers younger than `subscription.standard.min_billable_age_days`
 *   are excluded. Absorbs "spin up + test + kill in five minutes" cases —
 *   a server has to stick around past the grace window before its first
 *   billed cycle.
 */
class OrganizationBillingStateComputer
{
    public function compute(Organization $organization): DesiredBillingState
    {
        $tierQuantities = array_fill_keys(
            array_map(fn (ServerTier $t) => $t->value, ServerTier::ordered()),
            0,
        );

        $minAgeDays = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $ageCutoff = now()->subDays($minAgeDays);

        $organization->servers()
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', $ageCutoff)
            ->get()
            ->each(function (Server $server) use (&$tierQuantities): void {
                $tier = $server->billingTier()->value;
                $tierQuantities[$tier] = ($tierQuantities[$tier] ?? 0) + 1;
            });

        return DesiredBillingState::fromCounts(
            tierQuantities: $tierQuantities,
            baseCents: (int) config('subscription.standard.base_cents', 2500),
            creditCents: (int) config('subscription.standard.included_credit_cents', 1000),
            tierPricesCents: (array) config('subscription.standard.tiers', []),
        );
    }
}
