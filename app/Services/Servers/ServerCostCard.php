<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Enums\ServerTier;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
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
    public function forServer(Server $server): array
    {
        $server->loadMissing(['providerCredential', 'sites']);
        $provider = $this->costObservatory->providerEstimateForServer($server);
        $tier = $server->billingTier();
        $dplyCents = $tier->priceCents();
        $siteCount = $server->sites->count();
        $capacity = $this->capacity($server);
        $providerCents = (int) ($provider['monthly_usd_cents'] ?? 0);
        $stackCents = $providerCents + $dplyCents;

        $nudge = $this->rightSizeNudge($server, $tier, $siteCount, $capacity);

        return [
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
                'provider_partial' => ($provider['source'] ?? '') === 'unknown',
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
     * @return array{cpu_pct: ?float, mem_pct: ?float, headroom_sites: ?int, metrics_at: ?string}
     */
    private function capacity(Server $server): array
    {
        $snapshot = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->orderByDesc('captured_at')
            ->first();

        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];
        $cpuPct = $this->metricFloat($payload, 'cpu_pct');
        $memPct = $this->metricFloat($payload, 'mem_pct');

        return [
            'cpu_pct' => $cpuPct,
            'mem_pct' => $memPct,
            'headroom_sites' => $this->estimateHeadroomSites($cpuPct, $memPct, max(1, $server->sites->count())),
            'metrics_at' => $snapshot?->captured_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  array{cpu_pct: ?float, mem_pct: ?float, headroom_sites: ?int, metrics_at: ?string}  $capacity
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

    private function formatUsdCents(int $cents): string
    {
        return '$'.number_format($cents / 100, 2).'/mo';
    }
}
