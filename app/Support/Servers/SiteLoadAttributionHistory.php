<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Support\Carbon;

/**
 * Rolling attribution history stored on server meta for 24h / 7d rollups.
 */
final class SiteLoadAttributionHistory
{
    /**
     * @param  array<string, mixed> $snapshot
     * @param  array<string, mixed> $existingMeta
     * @return array<string, mixed>
     */
    public function appendSnapshot(array $snapshot, array $existingMeta = []): array
    {
        $meta = $existingMeta;
        $key = (string) config('server_shared_host.attribution.history_meta_key', 'shared_host_attribution_history');
        $history = is_array($meta[$key] ?? null) ? $meta[$key] : [];

        $entry = [
            'checked_at' => (string) ($snapshot['checked_at'] ?? now()->toIso8601String()),
            'sites' => is_array($snapshot['sites'] ?? null) ? $snapshot['sites'] : [],
            'total' => is_array($snapshot['total'] ?? null) ? $snapshot['total'] : [],
        ];

        $history[] = $entry;

        $max = max(24, (int) config('server_shared_host.attribution.history_max_entries', 336));
        if (count($history) > $max) {
            $history = array_slice($history, -$max);
        }

        $meta[$key] = $history;

        return $meta;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(Server $server, string $range = '24h'): array
    {
        $key = (string) config('server_shared_host.attribution.history_meta_key', 'shared_host_attribution_history');
        $history = is_array($server->meta[$key] ?? null) ? $server->meta[$key] : [];
        $hours = $this->rangeHours($range);
        $since = now()->subHours($hours);

        return array_values(array_filter($history, static function (mixed $entry) use ($since): bool {
            if (! is_array($entry)) {
                return false;
            }
            $checkedAt = isset($entry['checked_at']) ? Carbon::parse((string) $entry['checked_at']) : null;

            return $checkedAt instanceof Carbon && $checkedAt->gte($since);
        }));
    }

    /**
     * @return array{
     *     range: string,
     *     hours: int,
     *     scan_count: int,
     *     rows: list<array{
     *         slug: string,
     *         peak_cpu_pct: float,
     *         peak_mem_mb: float,
     *         avg_cpu_pct: float,
     *         avg_mem_mb: float,
     *         peak_cpu_share_pct: ?float,
     *         peak_mem_share_pct: ?float,
     *     }>,
     * }
     */
    public function rollup(Server $server, string $range = '24h'): array
    {
        $entries = $this->entries($server, $range);
        $hours = $this->rangeHours($range);
        $aggregates = [];

        foreach ($entries as $entry) {
            $sites = is_array($entry['sites'] ?? null) ? $entry['sites'] : [];
            $totalCpu = (float) ($entry['total']['cpu_pct'] ?? 0);
            $totalMemKb = (int) ($entry['total']['mem_kb'] ?? 0);
            $totalMemMb = $totalMemKb > 0 ? round($totalMemKb / 1024, 1) : (float) ($entry['total']['mem_mb'] ?? 0);

            foreach ($sites as $siteRow) {
                if (! is_array($siteRow)) {
                    continue;
                }
                $slug = (string) ($siteRow['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                $cpuPct = (float) ($siteRow['cpu_pct'] ?? 0);
                $memMb = (float) ($siteRow['mem_mb'] ?? round(((int) ($siteRow['mem_kb'] ?? 0)) / 1024, 1));
                $cpuShare = $totalCpu > 0 ? round(($cpuPct / $totalCpu) * 100, 1) : null;
                $memShare = $totalMemMb > 0 ? round(($memMb / $totalMemMb) * 100, 1) : null;

                if (! isset($aggregates[$slug])) {
                    $aggregates[$slug] = [
                        'peak_cpu_pct' => $cpuPct,
                        'peak_mem_mb' => $memMb,
                        'cpu_sum' => $cpuPct,
                        'mem_sum' => $memMb,
                        'peak_cpu_share_pct' => $cpuShare,
                        'peak_mem_share_pct' => $memShare,
                        'samples' => 1,
                    ];

                    continue;
                }

                $aggregates[$slug]['peak_cpu_pct'] = max($aggregates[$slug]['peak_cpu_pct'], $cpuPct);
                $aggregates[$slug]['peak_mem_mb'] = max($aggregates[$slug]['peak_mem_mb'], $memMb);
                $aggregates[$slug]['cpu_sum'] += $cpuPct;
                $aggregates[$slug]['mem_sum'] += $memMb;
                $aggregates[$slug]['samples']++;
                if ($cpuShare !== null) {
                    $aggregates[$slug]['peak_cpu_share_pct'] = max((float) ($aggregates[$slug]['peak_cpu_share_pct'] ?? 0), $cpuShare);
                }
                if ($memShare !== null) {
                    $aggregates[$slug]['peak_mem_share_pct'] = max((float) ($aggregates[$slug]['peak_mem_share_pct'] ?? 0), $memShare);
                }
            }
        }

        $rows = [];
        foreach ($aggregates as $slug => $row) {
            $samples = max(1, (int) $row['samples']);
            $rows[] = [
                'slug' => $slug,
                'peak_cpu_pct' => (float) $row['peak_cpu_pct'],
                'peak_mem_mb' => (float) $row['peak_mem_mb'],
                'avg_cpu_pct' => round(((float) $row['cpu_sum']) / $samples, 1),
                'avg_mem_mb' => round(((float) $row['mem_sum']) / $samples, 1),
                'peak_cpu_share_pct' => $row['peak_cpu_share_pct'] ?? null,
                'peak_mem_share_pct' => $row['peak_mem_share_pct'] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['peak_cpu_pct'] <=> $a['peak_cpu_pct']) ?: ($b['peak_mem_mb'] <=> $a['peak_mem_mb']));

        return [
            'range' => $range,
            'hours' => $hours,
            'scan_count' => count($entries),
            'rows' => $rows,
        ];
    }

    private function rangeHours(string $range): int
    {
        $ranges = (array) config('server_shared_host.attribution.ranges', []);
        $hours = (($ranges[$range] ?? null) );

        return max(1, (int) ($hours ?? match ($range) {
            '7d' => 168,
            default => 24,
        }));
    }
}
