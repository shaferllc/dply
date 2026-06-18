<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Traffic-focused analytics for Edge site workspace (requests, bandwidth, trends).
 * Built on daily usage snapshots — not real-time HTTP access logs.
 */
final class EdgeSiteTrafficAnalytics
{
    public function __construct(
        private readonly EdgeSiteBillingAnalytics $billingAnalytics,
    ) {}

    /**
     * @param  array<string, mixed>|null  $billing  Pre-computed billing payload from {@see EdgeSiteBillingAnalytics::forSite()}.
     *                                              Pass when the caller already fetched it to avoid re-running the same
     *                                              two snapshot queries.
     * @return array<string, mixed>|null
     */
    public function forSite(Site $site, int $dailyDays = 30, ?array $billing = null): ?array
    {
        if (! $site->usesEdgeRuntime() || $site->isEdgePreview()) {
            return null;
        }

        $trackedHostnames = $site->edgeUsageHostnames();

        if ($site->usesOrgCloudflareEdge()) {
            return [
                'byo_cloudflare' => true,
                'tracked_hostnames' => $trackedHostnames,
                'has_snapshots' => false,
            ];
        }

        if ($site->status !== Site::STATUS_EDGE_ACTIVE || $site->edge_backend !== 'dply_edge') {
            return null;
        }

        $billing ??= $this->billingAnalytics->forSite($site, $dailyDays);
        if ($billing === null) {
            return null;
        }

        $daily = is_array($billing['daily'] ?? null) ? $billing['daily'] : [];
        $last7 = array_slice($daily, -7);
        $requests7d = (int) array_sum(array_column($last7, 'requests'));
        $bytesEgress7d = (int) array_sum(array_column($last7, 'bytes_egress'));
        $peakDay = $this->peakTrafficDay($daily);
        $lastCollectedDate = $daily !== []
            ? Carbon::parse((string) ($daily[array_key_last($daily)]['date'] ?? ''))->toDateString()
            : null;

        $analyticsZones = array_values(array_unique(array_filter(
            array_values($site->edgeUsageHostnameZones()),
        )));

        return array_merge($billing, [
            'byo_cloudflare' => false,
            'tracked_hostnames' => $trackedHostnames,
            'analytics_zones' => $analyticsZones,
            'requests_7d' => $requests7d,
            'bytes_egress_7d' => $bytesEgress7d,
            'avg_requests_per_day_7d' => $last7 !== []
                ? (int) round($requests7d / count($last7))
                : 0,
            'peak_day' => $peakDay,
            'last_collected_date' => $lastCollectedDate !== '' ? $lastCollectedDate : null,
            'collection_delay_note' => __('Stats update daily after the edge usage collection job (usually yesterday\'s traffic by mid-morning UTC).'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $daily
     * @return array<string, mixed>|null
     */
    private function peakTrafficDay(array $daily): ?array
    {
        $peak = null;

        foreach ($daily as $day) {
            if ($peak === null || (int) ($day['requests'] ?? 0) > (int) ($peak['requests'] ?? 0)) {
                $peak = $day;
            }
        }

        return $peak;
    }
}
