<?php

namespace App\Modules\Insights\Services;

use App\Models\InsightFinding;
use App\Models\InsightHealthSnapshot;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Support\Collection;

class OrganizationInsightsMetricsService
{
    /**
     *   open_by_severity: array{critical: int, warning: int, info: int},
     *   total_open: int,
     *   avg_health_score: float|null,
     *   worst_servers: list<array{id: string, name: string, open: int, worst: string|null}>
     * }|null
     */
    public function organizationSummary(?Organization $org): ?array
    {
        if (! $org instanceof Organization) {
            return null;
        }

        $serverIds = $org->serverIds();

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

        // Server-scoped only (whereNull site_id) so the count on the
        // /servers index badge matches what the operator actually sees
        // when they click through to /servers/{id}/insights — that page
        // hides per-site findings (those live on each site's Insights
        // page). Without this filter the badge over-promises.
        $rows = InsightFinding::query()
            ->whereIn('server_id', $serverIds)
            ->whereNull('site_id')
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
     * @param  Collection<string, covariant array{open: int, worst: string|null}>  $perServer
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
     * Latest health score per server, keyed by server id.
     *
     * DISTINCT ON picks the newest snapshot per box in one statement; on a tie
     * it returns a single row, where the old joinSub returned every tied row
     * and double-counted the box in the average.
     *
     * @param  Collection<int, string>  $serverIds
     * @return Collection<string, float>
     */
    public function latestHealthScores(Collection $serverIds): Collection
    {
        if ($serverIds->isEmpty()) {
            return collect();
        }

        return InsightHealthSnapshot::query()
            ->selectRaw('distinct on (server_id) server_id, score')
            ->whereIn('server_id', $serverIds)
            ->orderBy('server_id')
            ->orderByDesc('captured_at')
            ->pluck('score', 'server_id');
    }

    /**
     * @param  Collection<int, string>  $serverIds
     */
    protected function averageLatestHealthScore(Collection $serverIds): ?float
    {
        $scores = $this->latestHealthScores($serverIds);

        if ($scores->isEmpty()) {
            return null;
        }

        return round($scores->avg(), 1);
    }
}
