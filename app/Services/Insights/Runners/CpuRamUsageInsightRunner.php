<?php

namespace App\Services\Insights\Runners;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

class CpuRamUsageInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        $latest = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->orderByDesc('captured_at')
            ->first();

        if ($latest === null) {
            return [];
        }

        $cpu = $latest->payload['cpu_pct'] ?? null;
        $mem = $latest->payload['mem_pct'] ?? null;
        $cpuWarn = (float) ($parameters['cpu_warn_pct'] ?? config('insights.thresholds.cpu_warn_pct', 85));
        $memWarn = (float) ($parameters['mem_warn_pct'] ?? config('insights.thresholds.mem_warn_pct', 85));

        $cpuHigh = is_numeric($cpu) && (float) $cpu >= $cpuWarn;
        $memHigh = is_numeric($mem) && (float) $mem >= $memWarn;

        if (! $cpuHigh && ! $memHigh) {
            return [];
        }

        $parts = [];
        if ($cpuHigh) {
            $parts[] = __('CPU is at :pct%', ['pct' => round((float) $cpu, 1)]);
        }
        if ($memHigh) {
            $parts[] = __('RAM usage is at :pct%', ['pct' => round((float) $mem, 1)]);
        }

        return [
            new InsightCandidate(
                insightKey: 'cpu_ram_usage',
                dedupeHash: 'threshold',
                severity: ($cpuHigh && $memHigh) ? 'critical' : 'warning',
                title: __('High CPU or RAM usage'),
                body: implode('; ', $parts),
                meta: [
                    'cpu_pct' => $cpu,
                    'mem_pct' => $mem,
                    'snapshot_at' => $latest->captured_at?->toIso8601String(),
                ],
            ),
        ];
    }
}
