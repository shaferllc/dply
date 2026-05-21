<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;
use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;

/**
 * Builds a {@see DesiredBillingState} for an organization by scanning its
 * currently *billable* units. Two kinds:
 *
 * - **Spec-tiered servers** — ready VM/container hosts, classified XS–XL via
 *   the server's billingTier() accessor.
 * - **Serverless functions** — code actions on active function-Sites (FaaS
 *   hosts), billed at a flat per-action fee. A Site is an OpenWhisk package
 *   that may hold several code actions; sequences and triggers bill nothing.
 *
 * Two filters apply on top of fleet-membership, to both kinds:
 *
 * - **Status:** only operational units count — `ready` servers,
 *   `functions_active` function-Sites. Provisioning/error/disconnected
 *   states are excluded so the customer isn't billed for transient artifacts.
 * - **Age:** units younger than `subscription.standard.min_billable_age_days`
 *   are excluded — absorbs "spin up + test + kill" cases.
 *
 * Serverless *host* servers (DO Functions / Lambda namespaces) are NOT
 * spec-tiered — they have no vCPU/RAM. They're skipped from the server scan;
 * their function-Sites are what bills.
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
                // Serverless hosts aren't spec-tiered servers — their
                // function-Sites bill separately, below.
                if ($server->isServerlessHost()) {
                    return;
                }

                $tier = $server->billingTier()->value;
                $tierQuantities[$tier] = ($tierQuantities[$tier] ?? 0) + 1;
            });

        // Serverless billing is metered per *code* action. A function-Site
        // is an OpenWhisk package that may hold several actions; sequences
        // (kind != code) and triggers bill nothing. A billable function-Site
        // always counts at least once, even before its actions have been
        // enumerated into `function_actions`, so the meter never regresses.
        $serverlessCount = 0;
        $organization->sites()
            ->where('status', Site::STATUS_FUNCTIONS_ACTIVE)
            ->where('created_at', '<=', $ageCutoff)
            ->withCount(['functionActions as code_action_count' => fn ($query) => $query->where('kind', FunctionAction::KIND_CODE)])
            ->get()
            ->each(function (Site $site) use (&$serverlessCount): void {
                $serverlessCount += max(1, (int) $site->code_action_count);
            });

        return DesiredBillingState::fromCounts(
            tierQuantities: $tierQuantities,
            baseCents: (int) config('subscription.standard.base_cents', 2500),
            creditCents: (int) config('subscription.standard.included_credit_cents', 1000),
            tierPricesCents: (array) config('subscription.standard.tiers', []),
            serverlessCount: $serverlessCount,
            serverlessUnitCents: (int) config('subscription.standard.serverless_cents', 200),
        );
    }
}
