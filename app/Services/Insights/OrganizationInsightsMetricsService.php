<?php

namespace App\Services\Insights;

use App\Models\InsightFinding;
use App\Models\InsightHealthSnapshot;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrganizationInsightsMetricsService
{
    /**
     * @return array{
     *   open_by_severity: array{critical: int, warning: int, info: int},
     *   total_open: int,
     *   avg_health_score: float|null,
     *   worst_servers: list<array{id: string, name: string, open: int, worst: string|null}>
     * }|null
     */
    public function fleetSummary(?Organization $org): ?array
    {
        if (! $org instanceof Organization) {
            return null;
        }

        $serverIds = Server::query()
            ->where('organization_id', $org->id)
            ->pluck('id');

        if ($serverIds->isEmpty()) {
            return [
                'open_by_severity' => ['critical' => 0, 'warning' => 0, 'info' => 0],
                'total_open' => 0,
                'avg_health_score' => null,
                'worst_servers' => [],
            ];
        }

        $rows = InsightFinding::query()
            ->whereIn('server_id', $serverIds)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->selectRaw('severity, count(*) as c')
            ->groupBy('severity')
            ->pluck('c', 'severity');

        $bySev = [
            'critical' => (int) ($rows['critical'] ?? 0),
            'warning' => (int) ($rows['warning'] ?? 0),
            'info' => (int) ($rows['info'] ?? 0),
        ];

        $totalOpen = array_sum($bySev);

        $perServer = $this->perServerRollup($serverIds);
        $worstServers = $this->topWorstServers($perServer, $serverIds, 3);

        $avgHealth = $this->averageLatestHealthScore($serverIds);

        return [
            'open_by_severity' => $bySev,
            'total_open' => $totalOpen,
            'avg_health_score' => $avgHealth,
            'worst_servers' => $worstServers,
        ];
    }

    /**
     * @param  Collection<int, string>  $serverIds
     * @return Collection<string, array{open: int, worst: string|null}>
     */
    public function perServerRollup(Collection $serverIds): Collection
    {
        if ($serverIds->isEmpty()) {
            return collect();
        }

        $rows = InsightFinding::query()
            ->whereIn('server_id', $serverIds)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->get(['server_id', 'severity']);

        $rank = [
            InsightFinding::SEVERITY_CRITICAL => 3,
            InsightFinding::SEVERITY_WARNING => 2,
            InsightFinding::SEVERITY_INFO => 1,
        ];

        $byServer = $rows->groupBy('server_id');
        $out = collect();

        foreach ($serverIds as $id) {
            $group = $byServer->get($id, collect());
            $worst = null;
            $max = 0;
            foreach ($group as $f) {
                $sev = (string) $f->severity;
                $r = $rank[$sev] ?? 0;
                if ($r > $max) {
                    $max = $r;
                    $worst = $sev;
                }
            }
            $out[$id] = ['open' => $group->count(), 'worst' => $worst];
        }

        return $out;
    }

    /**
     * @param  Collection<string, array{open: int, worst: string|null}>  $perServer
     * @param  Collection<int, string>  $serverIds
     * @return list<array{id: string, name: string, open: int, worst: string|null}>
     */
    protected function topWorstServers(Collection $perServer, Collection $serverIds, int $limit): array
    {
        $rank = [
            InsightFinding::SEVERITY_CRITICAL => 3,
            InsightFinding::SEVERITY_WARNING => 2,
            InsightFinding::SEVERITY_INFO => 1,
        ];

        $sorted = $serverIds
            ->map(fn (string $id): array => [
                'id' => $id,
                'open' => $perServer[$id]['open'] ?? 0,
                'worst' => $perServer[$id]['worst'] ?? null,
            ])
            ->filter(fn (array $row): bool => $row['open'] > 0)
            ->sortByDesc(function (array $row) use ($rank): int {
                $w = $row['worst'];

                return (($rank[$w] ?? 0) * 1000) + $row['open'];
            })
            ->take($limit)
            ->values();

        if ($sorted->isEmpty()) {
            return [];
        }

        $names = Server::query()
            ->whereIn('id', $sorted->pluck('id'))
            ->pluck('name', 'id');

        return $sorted->map(fn (array $row): array => [
            'id' => $row['id'],
            'name' => (string) ($names[$row['id']] ?? '?'),
            'open' => $row['open'],
            'worst' => $row['worst'],
        ])->all();
    }

    /**
     * @param  Collection<int, string>  $serverIds
     */
    protected function averageLatestHealthScore(Collection $serverIds): ?float
    {
        if ($serverIds->isEmpty()) {
            return null;
        }

        $sub = DB::table('insight_health_snapshots')
            ->select('server_id', DB::raw('MAX(captured_at) as max_captured_at'))
            ->whereIn('server_id', $serverIds)
            ->groupBy('server_id');

        $scores = InsightHealthSnapshot::query()
            ->joinSub($sub, 'latest', function ($join): void {
                $join->on('insight_health_snapshots.server_id', '=', 'latest.server_id')
                    ->on('insight_health_snapshots.captured_at', '=', 'latest.max_captured_at');
            })
            ->pluck('insight_health_snapshots.score');

        if ($scores->isEmpty()) {
            return null;
        }

        return round($scores->avg(), 1);
    }
}
