<?php

namespace App\Modules\Billing\Services;

use App\Enums\ServerTier;
use App\Models\FunctionAction;
use App\Models\LookoutProject;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerLogUsageDaily;
use App\Models\Site;
use App\Modules\Logs\Services\ServerLogEntitlements;
use App\Modules\Realtime\Models\RealtimeApp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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
        private SubscriptionPlanResolver $planResolver,
        private CloudResourceCostCalculator $cloudResourceCalculator,
        private ServerlessOrganizationUsageReader $serverlessUsageReader,
        private ServerlessUsageCostCalculator $serverlessUsageCostCalculator,
        private ServerlessResourceCostCalculator $serverlessResourceCalculator,
        private ServerResourceCostCalculator $serverResourceCalculator,
        private ServerLogEntitlements $serverLogEntitlements,
        private ServerLogUsageCostCalculator $serverLogUsageCostCalculator,
    ) {}

    /**
     * READY servers past the min-billable age, with the latest metric snapshot
     * eager-loaded. Request-scoped (static) memo: the tier scan here,
     * {@see BillingAnalytics::billableServers()}, and
     * {@see Organization::currentSubscriptionPlan()} all need this set —
     * sharing it collapses duplicate ready-server SELECTs into one.
     *
     * @var array<string, Collection<int, Server>>
     */
    private static array $readyBillableServersMemo = [];

    /**
     * Full {@see DesiredBillingState} per org for the request. Livewire billing
     * blades, trial banners ({@see Organization::owesNothingThisCycle}), and
     * analytics all call {@see compute()} — without this each access re-runs
     * site scans + usage SUMs (Debugbar duplicate-query noise).
     *
     * @var array<string, DesiredBillingState>
     */
    private static array $desiredStateMemo = [];

    /**
     * Request-scoped Schema::hasTable('function_actions') — information_schema
     * round-trips otherwise repeat once per compute() before the desired-state
     * memo lands (and whenever compute is flushed mid-request).
     */
    private static ?bool $functionActionsTableExists = null;

    /**
     * Metered log-bytes SUM keyed by org + period window.
     *
     * @var array<string, int>
     */
    private static array $serverLogBytesMemo = [];

    /**
     * @return Collection<int, Server>
     */
    public function readyBillableServers(Organization $organization): Collection
    {
        $key = (string) $organization->id;
        if (isset(self::$readyBillableServersMemo[$key])) {
            return self::$readyBillableServersMemo[$key];
        }

        $ageCutoff = now()->subDays(max(0, (int) config('subscription.standard.min_billable_age_days', 1)));

        return self::$readyBillableServersMemo[$key] = $organization->servers()
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', $ageCutoff)
            // billing tiers() (and the managed-server cost calc) read the latest
            // metric snapshot per server — eager load it to avoid an N+1.
            ->with('latestMetricSnapshot')
            ->get();
    }

    /**
     * BYO server count used to pick the flat plan — same filter as
     * {@see compute()}'s `$serverCount` (excludes managed-product hosts and
     * dply-hosted VMs billed cost-plus).
     */
    public function billableByoServerCount(Organization $organization): int
    {
        return $this->readyBillableServers($organization)
            ->reject(fn (Server $server) => $server->isManagedProductHost() || $server->usesManagedHosting())
            ->count();
    }

    /**
     * Drop request-scoped memos (ready servers + desired state + schema/usage
     * helpers). Call from TestCase tearDown and after fleet mutations that must
     * be visible to a later compute() in the same process.
     */
    public static function flushReadyBillableServersMemo(?string $organizationId = null): void
    {
        self::flushMemo($organizationId);
    }

    public static function flushMemo(?string $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$readyBillableServersMemo = [];
            self::$desiredStateMemo = [];
            self::$serverLogBytesMemo = [];
            self::$functionActionsTableExists = null;

            return;
        }

        unset(
            self::$readyBillableServersMemo[$organizationId],
            self::$desiredStateMemo[$organizationId],
        );

        foreach (array_keys(self::$serverLogBytesMemo) as $key) {
            if (str_starts_with($key, $organizationId.'|')) {
                unset(self::$serverLogBytesMemo[$key]);
            }
        }
    }

    public function compute(Organization $organization): DesiredBillingState
    {
        $key = (string) $organization->id;
        if (isset(self::$desiredStateMemo[$key])) {
            return self::$desiredStateMemo[$key];
        }

        return self::$desiredStateMemo[$key] = $this->computeFresh($organization);
    }

    private function computeFresh(Organization $organization): DesiredBillingState
    {
        $tierQuantities = array_fill_keys(
            array_map(fn (ServerTier $t) => $t->value, ServerTier::ordered()),
            0,
        );

        $minAgeDays = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $ageCutoff = now()->subDays($minAgeDays);

        // dply-managed VMs run on dply-owned Hetzner infra and are billed all-in
        // cost-plus, so they are excluded from the plan-tier scan and collected
        // separately. BYO servers continue to drive the flat plan.
        /** @var Collection<int, Server> $managedServers */
        $managedServers = collect();

        $this->readyBillableServers($organization)
            ->each(function (Server $server) use (&$tierQuantities, $managedServers): void {
                if ($server->isManagedProductHost()) {
                    return;
                }

                if ($server->usesManagedHosting()) {
                    $managedServers->push($server);

                    return;
                }

                $tier = $server->billingTier()->value;
                $tierQuantities[$tier] = ($tierQuantities[$tier] ?? 0) + 1;
            });

        // Comped managed servers (the beta free-CX22 grant, support credits) are
        // excluded from both the billed count and subtotal — the localized comp
        // decision lives on Server::isComped() / the comped_until column.
        $billableManagedServers = $managedServers->reject(fn (Server $server) => $server->isComped());
        $managedServerCount = $billableManagedServers->count();
        $managedServerSubtotalCents = $this->serverResourceCalculator->subtotalCents($billableManagedServers);

        $serverlessCount = 0;
        $cloudCount = 0;
        $edgeCount = 0;
        $edgeSsrCount = 0;

        // Billable Cloud apps are collected so their backing DigitalOcean
        // resources (containers, workers, databases, buckets) can be metered.
        /** @var Collection<int, Site> $billableCloudSites */
        $billableCloudSites = collect();

        // dply-managed serverless functions are collected so their usage
        // (metered) and managed DB/cache resources (cost-plus) can be billed
        // on top of the flat per-function fee. BYO functions are excluded.
        /** @var Collection<int, Site> $managedServerlessSites */
        $managedServerlessSites = collect();

        $siteQuery = $organization->sites()
            ->where('created_at', '<=', $ageCutoff);

        if ($this->functionActionsTableExists()) {
            $siteQuery->withCount(['functionActions as code_action_count' => fn ($query) => $query->where('kind', FunctionAction::KIND_CODE)]);
        }

        $siteQuery->get()
            ->each(function (Site $site) use (&$serverlessCount, &$cloudCount, &$edgeCount, &$edgeSsrCount, $billableCloudSites, $managedServerlessSites): void {
                if ($site->status === Site::STATUS_FUNCTIONS_ACTIVE) {
                    $serverlessCount += max(1, (int) $site->code_action_count);

                    if ($site->usesManagedServerless()) {
                        $managedServerlessSites->push($site);
                    }

                    return;
                }

                if ($site->status === Site::STATUS_CONTAINER_ACTIVE && $site->isDplyCloudSite() && ! $site->isCloudPreview()) {
                    $cloudCount++;
                    $billableCloudSites->push($site);

                    return;
                }

                if (
                    $site->status === Site::STATUS_EDGE_ACTIVE
                    && $site->edge_backend === 'dply_edge'
                    && ! $site->isEdgePreview()
                ) {
                    $edgeCount++;
                    $runtimeMode = strtolower((string) ($site->edgeMeta()['runtime_mode'] ?? 'static'));
                    if ($runtimeMode === 'ssr') {
                        $edgeSsrCount++;
                    }
                }
            });

        $cloudResourceSubtotalCents = $this->cloudResourceCalculator->subtotalCents($billableCloudSites);

        // Managed Realtime apps — billed per connection-tier (one line per tier,
        // quantity = active apps on that tier). Rows with a null/unknown tier are
        // attributed to the default tier via RealtimeApp::tierSlug().
        $realtimeTierQuantities = [];
        $organization->realtimeApps()
            ->where('status', RealtimeApp::STATUS_ACTIVE)
            ->where('created_at', '<=', $ageCutoff)
            ->get(['tier'])
            ->each(function (RealtimeApp $app) use (&$realtimeTierQuantities): void {
                $slug = $app->tierSlug();
                $realtimeTierQuantities[$slug] = ($realtimeTierQuantities[$slug] ?? 0) + 1;
            });

        // Managed Lookout error-tracking projects — billed per tier, the first
        // project per org free (a loss-leader). Dark until LOOKOUT_BILLING_ENABLED
        // so no line is added today. Projects are ordered oldest-first so the free
        // allowance lands on the longest-standing project (stable across cycles).
        $lookoutTierQuantities = [];
        if ((bool) config('lookout.billing_enabled', false)) {
            $freeRemaining = max(0, (int) config('lookout.free_projects_per_org', 1));
            $organization->lookoutProjects()
                ->where('status', LookoutProject::STATUS_ACTIVE)
                // Bundle-origin projects are the free tracely+Lookout perk — never
                // billed, and filtered in the QUERY so they can't even consume the
                // org's free-project allowance below. See docs/adr/bundled-products-sso.md.
                ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', LookoutProject::SOURCE_BUNDLE))
                ->where('created_at', '<=', $ageCutoff)
                ->orderBy('created_at')
                ->get(['tier', 'created_at'])
                ->each(function (LookoutProject $project) use (&$lookoutTierQuantities, &$freeRemaining): void {
                    if ($freeRemaining > 0) {
                        $freeRemaining--;

                        return;
                    }
                    $slug = $project->tierSlug();
                    $lookoutTierQuantities[$slug] = ($lookoutTierQuantities[$slug] ?? 0) + 1;
                });
        }

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

        // dply Logs ingest overage — metered pass-through, billed against the
        // org's plan entitlement (included GB + per-GB rate). Volume is the
        // metered bytes for the current month from server_log_usage_daily (PR A).
        // Dark until billing is enabled + a plan carries a rate; subtotal is 0
        // otherwise, so this never adds a line today. Reuses the Edge month window.
        $serverLogBytes = $this->serverLogBytesForPeriod(
            $organization,
            $usagePeriodStart->toDateString(),
            $usagePeriodEnd->toDateString(),
        );
        $serverLogEntitlement = $this->serverLogEntitlements->forOrganization($organization);
        $serverLogUsageEstimate = array_merge(
            $this->serverLogUsageCostCalculator->estimate($serverLogEntitlement, $serverLogBytes),
            [
                'period_start' => $usagePeriodStart->toDateString(),
                'period_end' => $usagePeriodEnd->toDateString(),
                'retention_days' => $serverLogEntitlement->retentionDays,
                'plan_key' => $serverLogEntitlement->planKey,
            ],
        );
        $serverLogUsageSubtotalCents = (int) ($serverLogUsageEstimate['subtotal_cents']);

        // Managed-serverless usage (metered invocations above the included
        // allowance) + managed DB/cache resources, both cost-plus. BYO
        // functions contribute nothing here.
        $managedServerlessCount = $managedServerlessSites->count();
        [$slPeriodStart, $slPeriodEnd] = $this->serverlessUsageReader->currentMonthWindow();
        $serverlessUsageTotals = $this->serverlessUsageReader->totalsForOrganization($organization, $slPeriodStart, $slPeriodEnd);
        $serverlessUsageEstimate = $this->serverlessUsageCostCalculator->estimate($serverlessUsageTotals, $managedServerlessCount);
        $serverlessUsageSubtotalCents = (int) ($serverlessUsageEstimate['subtotal_cents'] ?? 0)
            + $this->serverlessResourceCalculator->subtotalCents($managedServerlessSites);

        // The flat plan is chosen by billable BYO server count; size only
        // feeds the display-only breakdown carried in $tierQuantities.
        // The canonical fleet bill carries the TRUE plan price (chosen by BYO
        // server count) even for beta orgs — it's what "subscribe early" charges
        // and what the fleet preview shows as post-beta value. The beta $0
        // experience is a lifecycle/display concern, not baked in here: beta
        // orgs simply have no Stripe subscription and are never paused (see
        // Organization::trialState / betaFeeWaived). The free CX22 is the one
        // genuine waiver and is already excluded above via comped_until.
        $serverCount = array_sum($tierQuantities);
        $plan = $this->planResolver->resolveForServerCount($serverCount);

        return DesiredBillingState::fromPlanAndUsage(
            plan: $plan,
            tierQuantities: $tierQuantities,
            serverlessCount: $serverlessCount,
            serverlessUnitCents: (int) config('subscription.standard.serverless_cents', 200),
            serverlessUsageSubtotalCents: $serverlessUsageSubtotalCents,
            managedServerCount: $managedServerCount,
            managedServerSubtotalCents: $managedServerSubtotalCents,
            cloudCount: $cloudCount,
            cloudUnitCents: (int) config('subscription.standard.cloud_cents', 500),
            cloudResourceSubtotalCents: $cloudResourceSubtotalCents,
            edgeCount: $edgeCount,
            edgeUnitCents: (int) config('subscription.standard.edge_cents', 200),
            edgeSsrCount: $edgeSsrCount,
            edgeSsrUnitCents: (int) config('subscription.standard.edge_ssr_cents', 700),
            edgeUsageSubtotalCents: $edgeUsageSubtotalCents,
            edgeUsageEstimate: $edgeUsageEstimate,
            realtimeTierQuantities: $realtimeTierQuantities,
            lookoutTierQuantities: $lookoutTierQuantities,
            serverLogUsageSubtotalCents: $serverLogUsageSubtotalCents,
            serverLogUsageEstimate: $serverLogUsageEstimate,
        );
    }

    private function functionActionsTableExists(): bool
    {
        return self::$functionActionsTableExists ??= Schema::hasTable('function_actions');
    }

    private function serverLogBytesForPeriod(
        Organization $organization,
        string $periodStart,
        string $periodEnd,
    ): int {
        $key = (string) $organization->id.'|'.$periodStart.'|'.$periodEnd;
        if (isset(self::$serverLogBytesMemo[$key])) {
            return self::$serverLogBytesMemo[$key];
        }

        return self::$serverLogBytesMemo[$key] = (int) ServerLogUsageDaily::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('day', [$periodStart, $periodEnd])
            ->sum('bytes');
    }
}
