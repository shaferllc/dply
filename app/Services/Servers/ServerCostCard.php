<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Enums\ServerTier;
use App\Models\Server;
use App\Models\Site;
use App\Services\Billing\OrganizationCostObservatory;

/**
 * Per-server true cost card — provider estimate, dply tier fee, site count,
 * capacity headroom, and an honest right-size nudge.
 */
final class ServerCostCard
{
    public function __construct(
        private OrganizationCostObservatory $costObservatory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function forServer(Server $server): array
    {
        $server->loadMissing(['providerCredential', 'sites']);
        $provider = $this->costObservatory->providerEstimateForServer($server);
        $tier = $server->billingTier();
        // Spec-tiered per-server fee only applies to VMs dply runs on its own
        // provider account (HOSTING_BACKEND_DPLY). BYO servers — where the
        // customer brings their own credential/SSH key and pays the provider
        // directly — don't get tier-priced; same for managed-product hosts
        // (serverless / dply-cloud / dply-edge), which have their own
        // per-product pricing models.
        $chargesTierFee = $server->usesManagedHosting() && ! $server->isManagedProductHost();
        $dplyCents = $chargesTierFee ? $tier->priceCents() : 0;
        $siteCount = $server->sites->count();
        $capacity = $this->capacity($server);
        $hardware = $this->hardware($server, $tier);
        $providerCents = (int) ($provider['monthly_usd_cents']);
        $stackCents = $providerCents + $dplyCents;
        $providerKnown = ($provider['source'] ?? '') !== 'unknown';

        $nudge = $this->rightSizeNudge($server, $tier, $siteCount, $capacity);
        $forgePerServer = (int) config('subscription.standard.observatory.forge_per_server_cents', 1200);
        $deltaVsForge = $stackCents - ($forgePerServer + $providerCents);
        $perSiteCents = $siteCount > 0 ? (int) round($stackCents / $siteCount) : null;

        $summary = [
            'stack_cents' => $stackCents,
            'provider_cents' => $providerCents,
            'dply_cents' => $dplyCents,
            'charges_tier_fee' => $chargesTierFee,
            'site_count' => $siteCount,
            'per_site_cents' => $perSiteCents,
            'forge_baseline_cents' => $forgePerServer,
            'delta_vs_forge_cents' => $deltaVsForge,
            'cpu_pct' => $capacity['cpu_pct'],
            'mem_pct' => $capacity['mem_pct'],
            'provider_known' => $providerKnown,
            'metrics_fresh' => $capacity['metrics_fresh'],
        ];

        $alerts = $this->buildAlerts($provider, $capacity, $nudge);
        $overall = $this->resolveOverall($nudge, $providerKnown, $capacity);

        return [
            'overall' => $overall,
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'summary' => $summary,
            'hardware' => $hardware,
            'tiers' => $this->tierLadder($tier),
            'breakdown' => $this->costBreakdown($providerCents, $dplyCents, $stackCents),
            'site_rows' => $this->siteRows($server, $perSiteCents),
            'provider' => $provider,
            'dply' => [
                'tier' => $tier->value,
                'tier_label' => $tier->label(),
                'monthly_cents' => $dplyCents,
                'formatted' => $this->formatUsdCents($dplyCents),
            ],
            'sites' => [
                'count' => $siteCount,
            ],
            'capacity' => $capacity,
            'totals' => [
                'monthly_usd_cents' => $stackCents,
                'provider_cents' => $providerCents,
                'dply_cents' => $dplyCents,
                'formatted' => $this->formatUsdCents($stackCents),
                'provider_partial' => ! $providerKnown,
            ],
            'comparison' => [
                'forge_per_server_cents' => $forgePerServer,
                'forge_plus_provider_cents' => $forgePerServer + $providerCents,
                'dply_plus_provider_cents' => $stackCents,
                'delta_vs_forge_cents' => $deltaVsForge,
            ],
            'nudge' => $nudge,
            'disclaimer' => __('Provider cost is a catalog estimate or a note you saved — not an invoiced amount. Dply bills its platform fee separately.'),
        ];
    }

    /**
     * Compact summary for the server overview shortcut card.
     *
     * @return array{formatted_total: string, nudge_title: ?string, nudge_severity: ?string}|null
     */
    public function overviewSummary(Server $server): ?array
    {
        if (! $server->isVmHost() || $server->isManagedProductHost()) {
            return null;
        }

        $report = $this->forServer($server);
        $nudge = is_array($report['nudge'] ?? null) ? $report['nudge'] : null;

        return [
            'formatted_total' => (string) ($report['totals']['formatted'] ?? ''),
            'nudge_title' => is_string($nudge['title'] ?? null) ? $nudge['title'] : null,
            'nudge_severity' => is_string($nudge['severity'] ?? null) ? $nudge['severity'] : null,
        ];
    }

    /**
     * @return array{cpu_pct: ?float, mem_pct: ?float, headroom_sites: ?int, metrics_at: ?string, metrics_fresh: bool, metrics_age_hours: ?int}
     */
    private function capacity(Server $server): array
    {
        $snapshot = $server->latestMetricSnapshot;

        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];
        $cpuPct = $this->metricFloat($payload, 'cpu_pct');
        $memPct = $this->metricFloat($payload, 'mem_pct');
        $capturedAt = $snapshot?->captured_at;
        $staleHours = max(1, (int) config('server_cost_card.metrics_stale_hours', 24));
        $metricsFresh = $capturedAt !== null && $capturedAt->gte(now()->subHours($staleHours));
        $metricsAgeHours = $capturedAt !== null
            ? (int) max(0, $capturedAt->diffInHours(now()))
            : null;

        return [
            'cpu_pct' => $cpuPct,
            'mem_pct' => $memPct,
            'headroom_sites' => $this->estimateHeadroomSites($cpuPct, $memPct, max(1, $server->sites->count())),
            'metrics_at' => $capturedAt?->toIso8601String(),
            'metrics_fresh' => $metricsFresh,
            'metrics_age_hours' => $metricsAgeHours,
        ];
    }

    /**
     * @return array{
     *   cpu_count: ?int,
     *   mem_mb: ?int,
     *   mem_formatted: ?string,
     *   tier: string,
     *   tier_label: string,
     *   provider: ?string,
     *   plan: ?string,
     *   region: ?string,
     * }
     */
    private function hardware(Server $server, ServerTier $tier): array
    {
        $snapshot = $server->latestMetricSnapshot;
        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];

        $cpuCount = isset($payload['cpu_count']) && is_numeric($payload['cpu_count'])
            ? (int) $payload['cpu_count']
            : null;

        $memMb = isset($payload['mem_total_kb']) && is_numeric($payload['mem_total_kb'])
            ? (int) round((float) $payload['mem_total_kb'] / 1024)
            : null;

        return [
            'cpu_count' => $cpuCount,
            'mem_mb' => $memMb,
            'mem_formatted' => $memMb !== null ? $this->formatMemory($memMb) : null,
            'tier' => $tier->value,
            'tier_label' => $tier->label(),
            'provider' => $server->provider->label(),
            'plan' => (string) ($server->size ?: '') ?: null,
            'region' => (string) ($server->region ?: '') ?: null,
        ];
    }

    /**
     * @return list<array{value: string, label: string, price_cents: int, formatted: string, current: bool}>
     */
    private function tierLadder(ServerTier $current): array
    {
        return array_map(
            static fn (ServerTier $tier): array => [
                'value' => $tier->value,
                'label' => $tier->label(),
                'price_cents' => $tier->priceCents(),
                'formatted' => '$'.number_format($tier->priceCents() / 100, 2).'/mo',
                'current' => $tier === $current,
            ],
            ServerTier::ordered(),
        );
    }

    /**
     * @return array{provider_pct: float, dply_pct: float}
     */
    private function costBreakdown(int $providerCents, int $dplyCents, int $stackCents): array
    {
        if ($stackCents <= 0) {
            return ['provider_pct' => 0.0, 'dply_pct' => 0.0];
        }

        return [
            'provider_pct' => round(($providerCents / $stackCents) * 100, 1),
            'dply_pct' => round(($dplyCents / $stackCents) * 100, 1),
        ];
    }

    /**
     * @return list<array{id: string, name: string, href: ?string, allocated_cents: ?int, formatted: ?string}>
     */
    private function siteRows(Server $server, ?int $perSiteCents): array
    {
        return $server->sites
            ->sortBy('name')
            ->values()
            ->map(static function (Site $site) use ($server, $perSiteCents): array {
                return [
                    'id' => (string) $site->id,
                    'name' => (string) $site->name,
                    'href' => route('sites.show', ['server' => $server, 'site' => $site]),
                    'allocated_cents' => $perSiteCents,
                    'formatted' => $perSiteCents !== null
                        ? '$'.number_format($perSiteCents / 100, 2).'/mo'
                        : null,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed> $provider
     * @param  array{cpu_pct: ?float, mem_pct: ?float, headroom_sites: ?int, metrics_at: ?string, metrics_fresh: bool, metrics_age_hours: ?int}  $capacity
     * @param  array{kind: string, severity: string, title: string, message: string}|null  $nudge
     * @return list<array{severity: string, title: string, message: string, action_label: ?string, action_route: ?string, action_anchor: ?string}>
     */
    private function buildAlerts(array $provider, array $capacity, ?array $nudge): array
    {
        $alerts = [];

        if (($provider['source'] ?? '') === 'unknown') {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Provider cost unknown'),
                'message' => (string) ($provider['detail'] ?? __('Add a monthly cost note on Settings or connect a supported provider credential for catalog lookup.')),
                'action_label' => __('Edit cost notes'),
                'action_route' => 'servers.settings',
                'action_anchor' => 'settings-cost',
            ];
        }

        if ($capacity['metrics_at'] === null) {
            $alerts[] = [
                'severity' => 'info',
                'title' => __('Utilization metrics pending'),
                'message' => __('Guest metrics have not reported yet — right-size nudges and headroom estimates appear after the first monitor snapshot.'),
                'action_label' => __('Open Monitor'),
                'action_route' => 'servers.monitor',
                'action_anchor' => null,
            ];
        } elseif (! $capacity['metrics_fresh']) {
            $alerts[] = [
                'severity' => 'info',
                'title' => __('Metrics may be stale'),
                'message' => trans_choice(
                    'Last snapshot was :hours hour ago — refresh Monitor before trusting utilization nudges.|Last snapshot was :hours hours ago — refresh Monitor before trusting utilization nudges.',
                    (int) ($capacity['metrics_age_hours'] ?? 0),
                    ['hours' => (int) ($capacity['metrics_age_hours'] ?? 0)],
                ),
                'action_label' => __('Open Monitor'),
                'action_route' => 'servers.monitor',
                'action_anchor' => null,
            ];
        }

        if ($nudge !== null) {
            $alerts[] = [
                'severity' => (string) ($nudge['severity'] ?? 'info'),
                'title' => (string) ($nudge['title'] ?? ''),
                'message' => (string) ($nudge['message'] ?? ''),
                'action_label' => null,
                'action_route' => null,
                'action_anchor' => null,
            ];
        }

        return $alerts;
    }

    /**
     * @param  array{kind: string, severity: string, title: string, message: string}|null  $nudge
     * @param  array{cpu_pct: ?float, mem_pct: ?float, headroom_sites: ?int, metrics_at: ?string, metrics_fresh: bool, metrics_age_hours: ?int}  $capacity
     */
    private function resolveOverall(?array $nudge, bool $providerKnown, array $capacity): string
    {
        if (($nudge['severity'] ?? null) === 'warning') {
            return 'critical';
        }

        if (! $providerKnown) {
            return 'warning';
        }

        if ($nudge !== null) {
            return 'info';
        }

        if ($capacity['metrics_at'] === null || ! $capacity['metrics_fresh']) {
            return 'info';
        }

        return 'healthy';
    }

    /**
     * @param  array<string, mixed> $payload
     */
    private function metricFloat(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? round((float) $value, 1) : null;
    }

    private function estimateHeadroomSites(?float $cpuPct, ?float $memPct, int $siteCount): ?int
    {
        if ($cpuPct === null && $memPct === null) {
            return null;
        }

        $headroomPct = (float) config('server_cost_card.right_size.headroom_util_pct', 40);
        $minPerSite = (float) config('server_cost_card.right_size.min_per_site_pct', 5);

        $estimates = [];
        foreach ([$cpuPct, $memPct] as $pct) {
            if ($pct === null || $pct >= $headroomPct) {
                continue;
            }

            $perSite = max($minPerSite, $pct / $siteCount);
            $remaining = max(0.0, $headroomPct - $pct);
            $estimates[] = (int) floor($remaining / $perSite);
        }

        if ($estimates === []) {
            return 0;
        }

        return max(0, min($estimates));
    }

    /**
     * @param  array{cpu_pct: ?float, mem_pct: ?float, headroom_sites: ?int, metrics_at: ?string, metrics_fresh: bool, metrics_age_hours: ?int}  $capacity
     * @return array{kind: string, severity: string, title: string, message: string}|null
     */
    private function rightSizeNudge(Server $server, ServerTier $tier, int $siteCount, array $capacity): ?array
    {
        $cpuPct = $capacity['cpu_pct'];
        $memPct = $capacity['mem_pct'];
        $headroom = $capacity['headroom_sites'];

        if ($cpuPct === null && $memPct === null) {
            return null;
        }

        $lowUtil = (float) config('server_cost_card.right_size.low_util_pct', 15);
        $hotUtil = (float) config('server_cost_card.right_size.hot_util_pct', 85);
        $minTierWeight = (int) config('server_cost_card.right_size.min_tier_weight_oversized', 3);

        $peak = max($cpuPct ?? 0.0, $memPct ?? 0.0);
        $low = ($cpuPct === null || $cpuPct <= $lowUtil)
            && ($memPct === null || $memPct <= $lowUtil)
            && ($cpuPct !== null || $memPct !== null);

        if ($peak >= $hotUtil) {
            return [
                'kind' => 'constrained',
                'severity' => 'warning',
                'title' => __('Running hot'),
                'message' => __('Guest metrics show :pct%+ utilization — consider splitting sites or upgrading the provider plan before adding more load.', [
                    'pct' => number_format($peak, 0),
                ]),
            ];
        }

        if ($siteCount <= 1 && $low && $tier->weight() >= $minTierWeight) {
            $utilLabel = $cpuPct !== null ? number_format($cpuPct, 0).'% CPU' : number_format((float) $memPct, 0).'% memory';

            return [
                'kind' => 'oversized',
                'severity' => 'info',
                'title' => __('Room to downsize'),
                'message' => __('You have :count site on :tier at about :util — a smaller dply tier or provider plan may fit.', [
                    'count' => $siteCount,
                    'tier' => $tier->label(),
                    'util' => $utilLabel,
                ]),
            ];
        }

        if ($headroom !== null && $headroom >= 1 && $peak < (float) config('server_cost_card.right_size.headroom_util_pct', 40)) {
            return [
                'kind' => 'headroom',
                'severity' => 'info',
                'title' => __('Headroom available'),
                'message' => trans_choice(
                    'Current load suggests room for about :count more small site|Current load suggests room for about :count more small sites',
                    $headroom,
                    ['count' => $headroom],
                ),
            ];
        }

        if ($siteCount >= 3 && $low && $tier->weight() >= $minTierWeight) {
            return [
                'kind' => 'consolidation',
                'severity' => 'info',
                'title' => __('Underutilized for this tier'),
                'message' => __(':count sites share this :tier host at low utilization — you may be paying for capacity you are not using.', [
                    'count' => $siteCount,
                    'tier' => $tier->label(),
                ]),
            ];
        }

        return null;
    }

    private function formatMemory(int $memMb): string
    {
        if ($memMb >= 1024) {
            $gb = $memMb / 1024;

            return rtrim(rtrim(number_format($gb, 1), '0'), '.').' GB';
        }

        return $memMb.' MB';
    }

    private function formatUsdCents(int $cents): string
    {
        return '$'.number_format($cents / 100, 2).'/mo';
    }
}
