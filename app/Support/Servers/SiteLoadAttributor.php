<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Formats site load attribution rows from the SSH snapshot stored on the server meta.
 */
final class SiteLoadAttributor
{
    public function __construct(
        private SiteLoadAttributionHistory $history,
    ) {}

    /**
     * @return array{
     *     range: string,
     *     has_snapshot: bool,
     *     checked_at: ?Carbon,
     *     stale: bool,
     *     solo_tenant: bool,
     *     site_count: int,
     *     scan_count: int,
     *     rows: list<array<string, mixed>>,
     *     total: array{cpu_pct: float, mem_mb: float}|null,
     *     unattributed: array{cpu_pct: float, mem_mb: float}|null,
     * }
     */
    public function forServer(Server $server, string $range = 'current'): array
    {
        $server->loadMissing('sites');
        $siteCount = $server->sites->count();
        $soloTenant = $siteCount < 2;
        $range = in_array($range, ['current', '24h', '7d'], true) ? $range : 'current';

        if ($range !== 'current') {
            return $this->historicalAttribution($server, $range, $soloTenant, $siteCount);
        }

        $metaKey = (string) config('server_shared_host.attribution.meta_key', 'shared_host_attribution_snapshot');
        $snapshot = is_array($server->meta[$metaKey] ?? null) ? $server->meta[$metaKey] : null;

        if ($snapshot === null) {
            return [
                'range' => 'current',
                'has_snapshot' => false,
                'checked_at' => null,
                'stale' => false,
                'solo_tenant' => $soloTenant,
                'site_count' => $siteCount,
                'scan_count' => 0,
                'rows' => $this->placeholderRows($server),
                'total' => null,
                'unattributed' => null,
            ];
        }

        $checkedAt = isset($snapshot['checked_at']) ? Carbon::parse((string) $snapshot['checked_at']) : null;
        $ttlHours = (int) config('server_shared_host.attribution.snapshot_ttl_hours', 1);
        $stale = $checkedAt === null
            || $checkedAt->lt(now()->subHours(max(1, $ttlHours)));

        $sitesBySlug = $server->sites->keyBy(static fn (Site $site): string => (string) $site->slug);
        $rawSites = is_array($snapshot['sites'] ?? null) ? $snapshot['sites'] : [];

        $totalCpu = (float) ($snapshot['total']['cpu_pct'] ?? 0);
        $totalMemKb = (int) ($snapshot['total']['mem_kb'] ?? 0);
        $totalMemMb = $totalMemKb > 0 ? round($totalMemKb / 1024, 1) : (float) ($snapshot['total']['mem_mb'] ?? 0);

        $rows = [];
        foreach ($rawSites as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            /** @var Site|null $site */
            $site = $sitesBySlug->get($slug);
            $cpuPct = (float) ($row['cpu_pct'] ?? 0);
            $memMb = (float) ($row['mem_mb'] ?? round(((int) ($row['mem_kb'] ?? 0)) / 1024, 1));

            $rows[] = [
                'slug' => $slug,
                'name' => $site !== null ? (string) $site->name : $slug,
                'href' => $site !== null ? route('sites.show', ['server' => $server, 'site' => $site]) : route('servers.sites', $server),
                'cpu_pct' => $cpuPct,
                'mem_mb' => $memMb,
                'cpu_share_pct' => $totalCpu > 0 ? round(($cpuPct / $totalCpu) * 100, 1) : null,
                'mem_share_pct' => $totalMemMb > 0 ? round(($memMb / $totalMemMb) * 100, 1) : null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['cpu_pct'] <=> $a['cpu_pct']) ?: ($b['mem_mb'] <=> $a['mem_mb']));

        $unattributed = is_array($snapshot['unattributed'] ?? null) ? $snapshot['unattributed'] : null;

        return [
            'range' => 'current',
            'has_snapshot' => true,
            'checked_at' => $checkedAt,
            'stale' => $stale,
            'solo_tenant' => $soloTenant,
            'site_count' => $siteCount,
            'scan_count' => 1,
            'rows' => $rows,
            'total' => [
                'cpu_pct' => $totalCpu,
                'mem_mb' => $totalMemMb,
            ],
            'unattributed' => $unattributed !== null ? [
                'cpu_pct' => (float) ($unattributed['cpu_pct'] ?? 0),
                'mem_mb' => (float) ($unattributed['mem_mb'] ?? 0),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historicalAttribution(Server $server, string $range, bool $soloTenant, int $siteCount): array
    {
        $rollup = $this->history->rollup($server, $range);
        $sitesBySlug = $server->sites->keyBy(static fn (Site $site): string => (string) $site->slug);
        $rows = [];

        foreach ($rollup['rows'] as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            /** @var Site|null $site */
            $site = $sitesBySlug->get($slug);
            $rows[] = [
                'slug' => $slug,
                'name' => $site !== null ? (string) $site->name : $slug,
                'href' => $site !== null ? route('sites.show', ['server' => $server, 'site' => $site]) : route('servers.sites', $server),
                'cpu_pct' => (float) ($row['peak_cpu_pct'] ?? 0),
                'mem_mb' => (float) ($row['peak_mem_mb'] ?? 0),
                'avg_cpu_pct' => (float) ($row['avg_cpu_pct'] ?? 0),
                'avg_mem_mb' => (float) ($row['avg_mem_mb'] ?? 0),
                'cpu_share_pct' => $row['peak_cpu_share_pct'] ?? null,
                'mem_share_pct' => $row['peak_mem_share_pct'] ?? null,
            ];
        }

        return [
            'range' => $range,
            'has_snapshot' => ($rollup['scan_count'] ?? 0) > 0,
            'checked_at' => null,
            'stale' => false,
            'solo_tenant' => $soloTenant,
            'site_count' => $siteCount,
            'scan_count' => (int) ($rollup['scan_count'] ?? 0),
            'rows' => $rows,
            'total' => null,
            'unattributed' => null,
        ];
    }

    /**
     * @return list<array{slug: string, path: string}>
     */
    public function siteScanPayload(Server $server): array
    {
        return $server->sites()
            ->get(['slug', 'repository_path'])
            ->map(static fn (Site $site): array => [
                'slug' => (string) $site->slug,
                'path' => $site->effectiveRepositoryPath(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function placeholderRows(Server $server): array
    {
        return $server->sites
            ->sortBy('name')
            ->values()
            ->map(static fn (Site $site): array => [
                'slug' => (string) $site->slug,
                'name' => (string) $site->name,
                'href' => route('sites.show', ['server' => $server, 'site' => $site]),
                'cpu_pct' => 0.0,
                'mem_mb' => 0.0,
                'cpu_share_pct' => null,
                'mem_share_pct' => null,
            ])
            ->all();
    }
}
