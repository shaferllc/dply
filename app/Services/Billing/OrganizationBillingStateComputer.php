<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;
use App\Models\FunctionAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;

/**
 * Builds a {@see DesiredBillingState} for an organization by scanning its
 * currently *billable* units. Four kinds:
 *
 * - **Spec-tiered BYO servers** — ready VM hosts the customer SSHs into,
 *   classified XS–XL via billingTier(). dply-managed logical hosts (Cloud,
 *   Edge, serverless namespaces) are excluded from this scan.
 * - **Serverless functions** — code actions on active function-Sites.
 * - **dply Cloud apps** — container_active sites on container_backend
 *   `dply_cloud`, excluding branch previews.
 * - **dply Edge sites** — edge_active sites with edge_backend set, excluding
 *   branch previews.
 *
 * Age filter: units younger than min_billable_age_days are excluded.
 */
class OrganizationBillingStateComputer
{
    public function __construct(
        private EdgeOrganizationUsageReader $usageReader,
        private EdgeUsageCostCalculator $usageCostCalculator,
    ) {}

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
                if ($server->isManagedProductHost()) {
                    return;
                }

                $tier = $server->billingTier()->value;
                $tierQuantities[$tier] = ($tierQuantities[$tier] ?? 0) + 1;
            });

        $serverlessCount = 0;
        $cloudCount = 0;
        $edgeCount = 0;

        $organization->sites()
            ->where('created_at', '<=', $ageCutoff)
            ->withCount(['functionActions as code_action_count' => fn ($query) => $query->where('kind', FunctionAction::KIND_CODE)])
            ->get()
            ->each(function (Site $site) use (&$serverlessCount, &$cloudCount, &$edgeCount): void {
                if ($site->status === Site::STATUS_FUNCTIONS_ACTIVE) {
                    $serverlessCount += max(1, (int) $site->code_action_count);

                    return;
                }

                if ($site->status === Site::STATUS_CONTAINER_ACTIVE && $site->isDplyCloudSite() && ! $site->isCloudPreview()) {
                    $cloudCount++;

                    return;
                }

                if ($site->status === Site::STATUS_EDGE_ACTIVE && $site->usesEdgeRuntime() && ! $site->isEdgePreview()) {
                    $edgeCount++;
                }
            });

        [$usagePeriodStart, $usagePeriodEnd] = $this->usageReader->currentMonthWindow();
        $usageTotals = $this->usageReader->totalsForOrganization($organization, $usagePeriodStart, $usagePeriodEnd);
        $edgeUsageEstimate = $this->usageCostCalculator->estimate($usageTotals, $edgeCount);
        $edgeUsageEstimate = array_merge($edgeUsageEstimate, [
            'period_start' => $usagePeriodStart->toDateString(),
            'period_end' => $usagePeriodEnd->toDateString(),
            'requests' => $usageTotals->requests,
            'bytes_egress' => $usageTotals->bytesEgress,
            'r2_storage_bytes' => $usageTotals->r2StorageBytes,
        ]);
        $edgeUsageSubtotalCents = (int) ($edgeUsageEstimate['subtotal_cents'] ?? 0);

        return DesiredBillingState::fromCounts(
            tierQuantities: $tierQuantities,
            baseCents: (int) config('subscription.standard.base_cents', 2500),
            creditCents: (int) config('subscription.standard.included_credit_cents', 1000),
            tierPricesCents: (array) config('subscription.standard.tiers', []),
            serverlessCount: $serverlessCount,
            serverlessUnitCents: (int) config('subscription.standard.serverless_cents', 200),
            cloudCount: $cloudCount,
            cloudUnitCents: (int) config('subscription.standard.cloud_cents', 500),
            edgeCount: $edgeCount,
            edgeUnitCents: (int) config('subscription.standard.edge_cents', 200),
            edgeUsageSubtotalCents: $edgeUsageSubtotalCents,
            edgeUsageEstimate: $edgeUsageEstimate,
        );
    }
}
