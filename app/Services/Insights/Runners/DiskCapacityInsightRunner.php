<?php

namespace App\Services\Insights\Runners;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

class DiskCapacityInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        $snaps = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->orderByDesc('captured_at')
            ->limit(8)
            ->get()
            ->sortBy('captured_at')
            ->values();

        if ($snaps->count() < 3) {
            return [];
        }

        $first = (float) ($snaps->first()->payload['disk_pct'] ?? 0);
        $last = (float) ($snaps->last()->payload['disk_pct'] ?? 0);
        $delta = $last - $first;
        if ($delta <= 0.5 || $last < 70) {
            return [];
        }

        $hours = $snaps->last()->captured_at?->diffInHours($snaps->first()->captured_at) ?: 1;
        $perDay = ($delta / max(1, $hours)) * 24;
        $daysTo95 = $perDay > 0.1 ? (95 - $last) / ($perDay / 24) : 9999;

        if ($daysTo95 > 60 || $daysTo95 < 0) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'disk_capacity_forecast',
                dedupeHash: 'trend',
                severity: $last >= 90 ? 'critical' : 'warning',
                title: __('Disk usage trending up'),
                body: __('Disk is at :pct% (~:days days to ~95% at recent trend — approximate).', [
                    'pct' => round($last, 1),
                    'days' => (int) round(max(0, $daysTo95)),
                ]),
                meta: [
                    'disk_pct_now' => $last,
                    'snapshots_used' => $snaps->count(),
                ],
            ),
        ];
    }
}
