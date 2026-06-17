<?php

namespace App\Services\Sites;

use App\Models\SiteUptimeCheckResult;
use App\Models\SiteUptimeIncident;
use App\Models\SiteUptimeMonitor;
use App\Services\Status\MonitorOperationalState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-side history for a monitor's inline detail: uptime % over 24h/7d/30d, a
 * bucketed 24h latency series for the sparkline, and the recent incident
 * timeline. Outages count against uptime %; degraded counts as up (it shows on
 * the timeline but doesn't tank the number). SSL-expiry warnings are not
 * incidents and never appear here.
 */
class SiteUptimeHistorySummary
{
    /**
     * @return array{
     *   uptime: array{'24h': ?float, '7d': ?float, '30d': ?float},
     *   latency: list<array{at:int,min:float,avg:float,max:float}>,
     *   incidents: Collection<int, SiteUptimeIncident>,
     *   has_data: bool
     * }
     */
    /** @return array<string, mixed> */
    public function forMonitor(SiteUptimeMonitor $monitor): array
    {
        $now = now();
        $d1 = $now->copy()->subDay();
        $d7 = $now->copy()->subDays(7);
        $d30 = $now->copy()->subDays(30);

        $agg = SiteUptimeCheckResult::query()
            ->where('site_uptime_monitor_id', $monitor->id)
            ->where('checked_at', '>=', $d30)
            ->selectRaw('count(*) as total_30d')
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as down_30d', [MonitorOperationalState::OUTAGE])
            ->selectRaw('sum(case when checked_at >= ? then 1 else 0 end) as total_7d', [$d7])
            ->selectRaw('sum(case when checked_at >= ? and state = ? then 1 else 0 end) as down_7d', [$d7, MonitorOperationalState::OUTAGE])
            ->selectRaw('sum(case when checked_at >= ? then 1 else 0 end) as total_24h', [$d1])
            ->selectRaw('sum(case when checked_at >= ? and state = ? then 1 else 0 end) as down_24h', [$d1, MonitorOperationalState::OUTAGE])
            ->first();

        $uptime = [
            '24h' => $this->percent((int) ($agg->total_24h ?? 0), (int) ($agg->down_24h ?? 0)),
            '7d' => $this->percent((int) ($agg->total_7d ?? 0), (int) ($agg->down_7d ?? 0)),
            '30d' => $this->percent((int) ($agg->total_30d ?? 0), (int) ($agg->down_30d ?? 0)),
        ];

        $incidents = $monitor->incidents()
            ->orderByRaw('resolved_at is null desc')
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        return [
            'uptime' => $uptime,
            'latency' => $this->latencySeries($monitor, $d1),
            'incidents' => $incidents,
            'has_data' => (int) ($agg->total_30d ?? 0) > 0,
        ];
    }

    private function percent(int $total, int $down): ?float
    {
        if ($total <= 0) {
            return null;
        }

        return round((($total - $down) / $total) * 100, 2);
    }

    /**
     * Hourly min/avg/max latency over the last 24h, shaped for x-metrics-line-chart.
     *
     * @return list<array{at:int,min:float,avg:float,max:float}>
     */
    private function latencySeries(SiteUptimeMonitor $monitor, Carbon $since): array
    {
        $rows = SiteUptimeCheckResult::query()
            ->where('site_uptime_monitor_id', $monitor->id)
            ->where('checked_at', '>=', $since)
            ->whereNotNull('latency_ms')
            ->orderBy('checked_at')
            ->get(['checked_at', 'latency_ms']);

        if ($rows->isEmpty()) {
            return [];
        }

        $buckets = [];
        foreach ($rows as $row) {
            $ts = $row->checked_at->copy()->startOfHour()->getTimestamp();
            $buckets[$ts][] = (float) $row->latency_ms;
        }
        ksort($buckets);

        $series = [];
        foreach ($buckets as $at => $values) {
            $series[] = [
                'at' => $at,
                'min' => (float) min($values),
                'avg' => round(array_sum($values) / count($values), 1),
                'max' => (float) max($values),
            ];
        }

        return $series;
    }
}
