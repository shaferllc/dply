<?php

namespace App\Services\Insights;

use App\Models\InsightFinding;
use App\Models\InsightHealthSnapshot;
use App\Models\Server;

class InsightHealthScoreService
{
    /**
     * @return array{score: int, counts: array<string, int>}
     */
    public function computeAndStore(Server $server): array
    {
        $open = InsightFinding::query()
            ->where('server_id', $server->id)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->get();

        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => $open->count(),
        ];

        foreach ($open as $f) {
            match ($f->severity) {
                InsightFinding::SEVERITY_CRITICAL => $counts['critical']++,
                InsightFinding::SEVERITY_WARNING => $counts['warning']++,
                default => $counts['info']++,
            };
        }

        $score = $this->scoreFromCounts($counts);

        InsightHealthSnapshot::query()->create([
            'server_id' => $server->id,
            'score' => $score,
            'counts' => $counts,
            'captured_at' => now(),
        ]);

        $this->pruneOldSnapshots($server->id);

        return ['score' => $score, 'counts' => $counts];
    }

    /**
     * @param  array<string, int>  $counts
     */
    public function scoreFromCounts(array $counts): int
    {
        $penalty = ($counts['critical'] ?? 0) * 25
            + ($counts['warning'] ?? 0) * 10
            + ($counts['info'] ?? 0) * 2;

        return max(0, min(100, 100 - $penalty));
    }

    protected function pruneOldSnapshots(string $serverId): void
    {
        $keepUntil = now()->subDays(8);
        InsightHealthSnapshot::query()
            ->where('server_id', $serverId)
            ->where('captured_at', '<', $keepUntil)
            ->delete();
    }

    /**
     * @return array<int, array{date: string, score: int}>
     */
    public function trendForServer(Server $server, int $days = 7): array
    {
        $rows = InsightHealthSnapshot::query()
            ->where('server_id', $server->id)
            ->where('captured_at', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('captured_at')
            ->get()
            ->groupBy(fn (InsightHealthSnapshot $s) => $s->captured_at->toDateString());

        $out = [];
        foreach ($rows as $date => $group) {
            $avg = (int) round($group->avg('score'));
            $out[] = ['date' => $date, 'score' => $avg];
        }

        return $out;
    }
}
