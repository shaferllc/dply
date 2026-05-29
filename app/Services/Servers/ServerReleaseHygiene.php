<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteRelease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only rollup of atomic release pressure, on-disk scan results, log sizes,
 * and failed-job counts for VM sites on a server.
 */
final class ServerReleaseHygiene
{
    /**
     * @return array{
     *     overall: string,
     *     alert_count: int,
     *     alerts: list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>,
     *     scan: array{checked_at: ?Carbon, never_scanned: bool, stale: bool},
     *     disk: array{pct: ?float, captured_at: ?Carbon},
     *     releases: array{atomic_site_count: int, total_stored: int, sites_over_keep: int, rows: list<array<string, mixed>>},
     *     logs: array{laravel_total_bytes: int, site_rows: list<array<string, mixed>>, system_logfiles: list<array<string, mixed>>, journal_usage: ?string},
     *     failed_jobs: array{total: int, sites_with_failures: int, rows: list<array<string, mixed>>},
     *     prune_command: array{name: string, description: string, installed: bool},
     * }
     */
    public function forServer(Server $server): array
    {
        $sites = $server->sites()->get([
            'id',
            'name',
            'slug',
            'server_id',
            'deploy_strategy',
            'releases_to_keep',
            'repository_path',
        ]);

        $snapshot = $this->snapshot($server);
        $checkedAt = $this->parseTime($snapshot['checked_at'] ?? null);
        $neverScanned = $checkedAt === null;
        $staleHours = max(1, (int) config('server_release_hygiene.stale_scan_hours', 24));
        $stale = $checkedAt !== null && $checkedAt->lt(now()->subHours($staleHours));

        $releases = $this->releases($sites, $snapshot);
        $logs = $this->logs($sites, $snapshot);
        $failedJobs = $this->failedJobs($sites, $snapshot);
        $disk = $this->disk($server);

        $alerts = array_merge(
            $this->scanAlerts($neverScanned, $stale),
            $this->diskAlerts($disk, $server),
            $this->releaseAlerts($releases, $server),
            $this->logAlerts($logs, $releases['rows']),
            $this->failedJobAlerts($failedJobs, $server),
        );

        usort($alerts, static function (array $a, array $b): int {
            $rank = static fn (string $severity): int => match ($severity) {
                'critical' => 0,
                'warning' => 1,
                default => 2,
            };

            return $rank($a['severity']) <=> $rank($b['severity']);
        });

        $overall = 'ok';
        foreach ($alerts as $alert) {
            if ($alert['severity'] === 'critical') {
                $overall = 'critical';
                break;
            }
            if ($alert['severity'] === 'warning' && $overall === 'ok') {
                $overall = 'warning';
            }
        }

        $pruneConfig = (array) config('server_release_hygiene.prune_saved_command', []);

        return [
            'overall' => $overall,
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'scan' => [
                'checked_at' => $checkedAt,
                'never_scanned' => $neverScanned,
                'stale' => $stale,
            ],
            'disk' => $disk,
            'releases' => $releases,
            'logs' => $logs,
            'failed_jobs' => $failedJobs,
            'prune_command' => [
                'name' => (string) ($pruneConfig['name'] ?? 'Prune atomic releases'),
                'description' => (string) ($pruneConfig['description'] ?? ''),
                'installed' => $server->recipes()
                    ->where('name', (string) ($pruneConfig['name'] ?? 'Prune atomic releases'))
                    ->exists(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $snapshot = $meta['release_hygiene_snapshot'] ?? [];

        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, mixed>
     */
    private function releases(Collection $sites, array $snapshot): array
    {
        $scanSites = collect($snapshot['sites'] ?? [])->keyBy('slug');
        $atomicSiteIds = $sites
            ->filter(fn (Site $site): bool => $site->isAtomicDeploys())
            ->pluck('id');

        $releaseCounts = $atomicSiteIds->isEmpty()
            ? collect()
            : SiteRelease::query()
                ->whereIn('site_id', $atomicSiteIds)
                ->selectRaw('site_id, count(*) as total')
                ->groupBy('site_id')
                ->pluck('total', 'site_id');

        $rows = [];
        $sitesOverKeep = 0;
        $totalStored = 0;

        foreach ($sites as $site) {
            if (! $site->isAtomicDeploys()) {
                continue;
            }

            $stored = (int) ($releaseCounts[(string) $site->id] ?? 0);
            $keep = max(1, min(50, (int) ($site->releases_to_keep ?? 5)));
            $totalStored += $stored;

            $scan = $scanSites->get((string) $site->slug, []);
            $onDisk = is_array($scan) ? (int) ($scan['release_count'] ?? 0) : 0;
            $extraOnDisk = is_array($scan) ? (int) ($scan['extra'] ?? 0) : 0;
            $releaseBytes = is_array($scan) ? (int) ($scan['release_bytes'] ?? 0) : 0;

            $overKeep = max($stored > $keep ? $stored - $keep : 0, $extraOnDisk);
            if ($stored > $keep || $extraOnDisk > 0) {
                $sitesOverKeep++;
            }

            $rows[] = [
                'site_id' => (string) $site->id,
                'site_name' => (string) $site->name,
                'slug' => (string) $site->slug,
                'stored' => $stored,
                'on_disk' => $onDisk,
                'keep' => $keep,
                'extra' => $overKeep,
                'release_bytes' => $releaseBytes,
                'href' => route('sites.show', ['server' => $site->server_id, 'site' => $site, 'section' => 'deploy']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['extra'] <=> $a['extra']) ?: ($b['release_bytes'] <=> $a['release_bytes']));

        return [
            'atomic_site_count' => count($rows),
            'total_stored' => $totalStored,
            'sites_over_keep' => $sitesOverKeep,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, mixed>
     */
    private function logs(Collection $sites, array $snapshot): array
    {
        $scanSites = collect(is_array($snapshot['sites'] ?? null) ? $snapshot['sites'] : [])->keyBy('slug');
        $system = is_array($snapshot['system'] ?? null) ? $snapshot['system'] : [];

        $laravelTotal = 0;
        $siteRows = [];

        foreach ($sites as $site) {
            $scan = $scanSites->get((string) $site->slug);
            if (! is_array($scan)) {
                continue;
            }

            $bytes = (int) ($scan['laravel_log_bytes'] ?? 0);
            $path = is_string($scan['laravel_log_path'] ?? null) ? (string) $scan['laravel_log_path'] : '';
            if ($bytes === 0 && $path === '') {
                continue;
            }

            $laravelTotal += $bytes;
            $siteRows[] = [
                'site_id' => (string) $site->id,
                'site_name' => (string) $site->name,
                'slug' => (string) $site->slug,
                'path' => $path,
                'bytes' => $bytes,
                'href' => route('sites.show', ['server' => $site->server_id, 'site' => $site, 'section' => 'logs']),
            ];
        }

        usort($siteRows, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $logfiles = [];
        foreach ($system['logfiles'] ?? [] as $file) {
            if (! is_array($file)) {
                continue;
            }
            $logfiles[] = [
                'path' => (string) ($file['path'] ?? ''),
                'bytes' => (int) ($file['bytes'] ?? 0),
            ];
        }

        usort($logfiles, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return [
            'laravel_total_bytes' => $laravelTotal,
            'site_rows' => $siteRows,
            'system_logfiles' => $logfiles,
            'journal_usage' => is_string($system['journal_usage'] ?? null) ? $system['journal_usage'] : null,
        ];
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, mixed>
     */
    private function failedJobs(Collection $sites, array $snapshot): array
    {
        $scanSites = collect($snapshot['sites'] ?? [])->keyBy('slug');
        $rows = [];
        $total = 0;
        $sitesWithFailures = 0;

        foreach ($sites as $site) {
            $scan = $scanSites->get((string) $site->slug);
            if (! is_array($scan) || ! array_key_exists('failed_jobs', $scan) || $scan['failed_jobs'] === null) {
                continue;
            }

            $count = max(0, (int) $scan['failed_jobs']);
            if ($count === 0) {
                continue;
            }

            $total += $count;
            $sitesWithFailures++;
            $rows[] = [
                'site_name' => (string) $site->name,
                'count' => $count,
                'href' => route('sites.show', ['server' => $site->server_id, 'site' => $site, 'section' => 'logs']),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'total' => $total,
            'sites_with_failures' => $sitesWithFailures,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{pct: ?float, captured_at: ?Carbon}
     */
    private function disk(Server $server): array
    {
        // Reuse the overview's memoized "latest snapshot" relation so the
        // health cockpit, cost card, billing tier, and this disk probe all
        // share one lookup instead of each firing their own.
        $snapshot = $server->latestMetricSnapshot;

        if ($snapshot === null) {
            return ['pct' => null, 'captured_at' => null];
        }

        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $pct = isset($payload['disk_pct']) ? (float) $payload['disk_pct'] : null;

        return [
            'pct' => $pct,
            'captured_at' => $snapshot->captured_at,
        ];
    }

    /**
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function scanAlerts(bool $neverScanned, bool $stale): array
    {
        if ($neverScanned) {
            return [[
                'severity' => 'warning',
                'title' => __('No hygiene scan yet'),
                'message' => __('Run a scan over SSH to measure release folders, Laravel logs, and failed jobs on disk.'),
                'href' => null,
                'link_label' => null,
            ]];
        }

        if ($stale) {
            return [[
                'severity' => 'warning',
                'title' => __('Hygiene scan is stale'),
                'message' => __('On-disk release and log sizes may have changed — refresh for current numbers.'),
                'href' => null,
                'link_label' => null,
            ]];
        }

        return [];
    }

    /**
     * @param  array{pct: ?float, captured_at: ?Carbon}  $disk
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function diskAlerts(array $disk, Server $server): array
    {
        $pct = $disk['pct'];
        if ($pct === null) {
            return [];
        }

        $critical = (float) config('server_health.capacity.critical_pct', 90);
        $warning = (float) config('server_health.capacity.warning_pct', 75);

        if ($pct >= $critical) {
            return [[
                'severity' => 'critical',
                'title' => __('Root disk critically full'),
                'message' => __('Disk is at :pct% — prune releases and rotate logs before deploys fail.', ['pct' => number_format($pct, 0)]),
                'href' => route('servers.health', $server),
                'link_label' => __('Open health'),
            ]];
        }

        if ($pct >= $warning) {
            return [[
                'severity' => 'warning',
                'title' => __('Root disk filling up'),
                'message' => __('Disk is at :pct% — review release folders and log sizes below.', ['pct' => number_format($pct, 0)]),
                'href' => null,
                'link_label' => null,
            ]];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $releases
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function releaseAlerts(array $releases, Server $server): array
    {
        if (($releases['sites_over_keep'] ?? 0) === 0) {
            return [];
        }

        $warningThreshold = max(1, (int) config('server_release_hygiene.thresholds.extra_releases_warning', 1));
        $criticalThreshold = max(1, (int) config('server_release_hygiene.thresholds.extra_releases_critical', 5));

        $maxExtra = 0;
        foreach ($releases['rows'] as $row) {
            $maxExtra = max($maxExtra, (int) ($row['extra'] ?? 0));
        }

        $severity = $maxExtra >= $criticalThreshold ? 'critical' : 'warning';

        return [[
            'severity' => $severity,
            'title' => trans_choice(
                ':count atomic site has extra release folders|:count atomic sites have extra release folders',
                (int) $releases['sites_over_keep'],
                ['count' => (int) $releases['sites_over_keep']],
            ),
            'message' => __('Deploys prune automatically, but drift happens after failed deploys or manual edits. Use the prune saved command or redeploy to reconcile.'),
            'href' => route('servers.run', $server),
            'link_label' => __('Open Run'),
        ]];
    }

    /**
     * @param  array<string, mixed>  $logs
     * @param  list<array<string, mixed>>  $releaseRows
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function logAlerts(array $logs, array $releaseRows): array
    {
        $warningMb = max(1, (int) config('server_release_hygiene.thresholds.laravel_log_warning_mb', 25));
        $criticalMb = max(1, (int) config('server_release_hygiene.thresholds.laravel_log_critical_mb', 100));
        $warningBytes = $warningMb * 1024 * 1024;
        $criticalBytes = $criticalMb * 1024 * 1024;

        $alerts = [];
        $total = (int) ($logs['laravel_total_bytes'] ?? 0);

        if ($total >= $criticalBytes) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => __('Laravel logs are very large'),
                'message' => __('Combined laravel.log size is :size — rotate or truncate before disk pressure breaks deploys.', [
                    'size' => $this->formatBytes($total),
                ]),
                'href' => null,
                'link_label' => null,
            ];
        } elseif ($total >= $warningBytes) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Laravel logs are growing'),
                'message' => __('Combined laravel.log size is :size across scanned sites.', [
                    'size' => $this->formatBytes($total),
                ]),
                'href' => null,
                'link_label' => null,
            ];
        }

        foreach ($releaseRows as $row) {
            $bytes = (int) ($row['release_bytes'] ?? 0);
            if ($bytes >= 1024 * 1024 * 1024) {
                $alerts[] = [
                    'severity' => 'warning',
                    'title' => __(':site release folder is large', ['site' => (string) $row['site_name']]),
                    'message' => __('releases/ uses :size on disk with :extra extra folders.', [
                        'size' => $this->formatBytes($bytes),
                        'extra' => (int) ($row['extra'] ?? 0),
                    ]),
                    'href' => $row['href'] ?? null,
                    'link_label' => __('Site deploy'),
                ];
                break;
            }
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $failedJobs
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function failedJobAlerts(array $failedJobs, Server $server): array
    {
        $total = (int) ($failedJobs['total'] ?? 0);
        if ($total === 0) {
            return [];
        }

        $warning = max(1, (int) config('server_release_hygiene.thresholds.failed_jobs_warning', 10));
        $critical = max(1, (int) config('server_release_hygiene.thresholds.failed_jobs_critical', 50));
        $severity = $total >= $critical ? 'critical' : ($total >= $warning ? 'warning' : 'info');

        if ($severity === 'info') {
            return [];
        }

        return [[
            'severity' => $severity,
            'title' => trans_choice(':count failed queue job|:count failed queue jobs', $total, ['count' => $total]),
            'message' => __('Failed jobs can fill storage and block retries — inspect site logs or run queue:failed on the server.'),
            'href' => route('servers.run', $server),
            'link_label' => __('Open Run'),
        ]];
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 1).' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
