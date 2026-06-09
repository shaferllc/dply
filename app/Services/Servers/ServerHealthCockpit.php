<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDeployment;
use App\Models\SiteRelease;
use App\Models\SupervisorProgram;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rolls up VM server health signals — guest metrics capacity, release
 * pressure, failed deploys, certificate expiry, and daemon drift — into
 * one operator-facing snapshot for the Health workspace.
 */
final class ServerHealthCockpit
{
    /**
     * @return array{
     *     overall: string,
     *     alert_count: int,
     *     alerts: list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>,
     *     capacity: array<string, mixed>,
     *     disks: list<array<string, mixed>>,
     *     releases: array<string, mixed>,
     *     deployments: array<string, mixed>,
     *     certificates: array<string, mixed>,
     *     daemons: array<string, mixed>,
     *     monitoring: array<string, mixed>,
     * }
     */
    public function forServer(Server $server): array
    {
        $sites = $server->sites()->get(['id', 'name', 'server_id', 'deploy_strategy', 'releases_to_keep']);
        $siteIds = $sites->pluck('id');

        $latestMetric = $server->latestMetricSnapshot;

        $payload = is_array($latestMetric?->payload) ? $latestMetric->payload : [];
        $capacity = $this->capacity($payload, $latestMetric?->captured_at);
        $disks = $this->disks($payload);
        $releases = $this->releases($sites);
        $deployments = $this->deployments($siteIds);
        $certificates = $this->certificates($server, $siteIds);
        $daemons = $this->daemons($server);
        $monitoring = $this->monitoring($server, $latestMetric?->captured_at, $payload);

        $alerts = array_merge(
            $this->capacityAlerts($capacity, $disks, $server),
            $this->releaseAlerts($releases, $server),
            $this->deploymentAlerts($deployments, $server),
            $this->certificateAlerts($certificates),
            $this->daemonAlerts($daemons, $server),
            $this->monitoringAlerts($monitoring, $server),
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

        return [
            'overall' => $overall,
            'alert_count' => count($alerts),
            'alerts' => $alerts,
            'capacity' => $capacity,
            'disks' => $disks,
            'releases' => $releases,
            'deployments' => $deployments,
            'certificates' => $certificates,
            'daemons' => $daemons,
            'monitoring' => $monitoring,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function capacity(array $payload, ?Carbon $capturedAt): array
    {
        $warning = (float) config('server_health.capacity.warning_pct', 75);
        $critical = (float) config('server_health.capacity.critical_pct', 90);

        $metrics = [
            'cpu_pct' => $this->metricFloat($payload, 'cpu_pct'),
            'mem_pct' => $this->metricFloat($payload, 'mem_pct'),
            'disk_pct' => $this->metricFloat($payload, 'disk_pct'),
            'load_1m' => $this->metricFloat($payload, 'load_1m'),
        ];

        $levels = [];
        foreach (['cpu_pct', 'mem_pct', 'disk_pct'] as $key) {
            $value = $metrics[$key];
            if ($value === null) {
                $levels[$key] = 'unknown';

                continue;
            }
            $levels[$key] = $value >= $critical ? 'critical' : ($value >= $warning ? 'warning' : 'ok');
        }

        $headroom = 'unknown';
        $pctValues = array_values(array_filter(
            [$metrics['cpu_pct'], $metrics['mem_pct'], $metrics['disk_pct']],
            static fn (?float $v): bool => $v !== null,
        ));
        if ($pctValues !== []) {
            $max = max($pctValues);
            $headroom = $max >= $critical ? 'low' : ($max >= $warning ? 'medium' : 'high');
        }

        return [
            'metrics' => $metrics,
            'levels' => $levels,
            'headroom' => $headroom,
            'captured_at' => $capturedAt,
            'has_samples' => $capturedAt !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function disks(array $payload): array
    {
        $raw = $payload['disks'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $disks = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pct = isset($row['pct']) ? (float) $row['pct'] : null;
            $disks[] = [
                'mount' => (string) ($row['mount'] ?? ''),
                'pct' => $pct,
                'used_bytes' => isset($row['used_bytes']) ? (int) $row['used_bytes'] : null,
                'total_bytes' => isset($row['total_bytes']) ? (int) $row['total_bytes'] : null,
            ];
        }

        return $disks;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, mixed>
     */
    private function releases($sites): array
    {
        $atomicSiteIds = $sites
            ->filter(fn (Site $site): bool => ($site->deploy_strategy ?? 'simple') === 'atomic')
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
            if (($site->deploy_strategy ?? 'simple') !== 'atomic') {
                continue;
            }

            $stored = (int) ($releaseCounts[(string) $site->id] ?? 0);
            $keep = max(1, min(50, (int) ($site->releases_to_keep ?? 5)));
            $totalStored += $stored;

            if ($stored > $keep) {
                $sitesOverKeep++;
            }

            $rows[] = [
                'site_id' => (string) $site->id,
                'site_name' => (string) $site->name,
                'stored' => $stored,
                'keep' => $keep,
                'href' => route('sites.show', ['server' => $site->server_id, 'site' => $site, 'section' => 'deploy']),
            ];
        }

        return [
            'atomic_site_count' => count($rows),
            'total_stored' => $totalStored,
            'sites_over_keep' => $sitesOverKeep,
            'rows' => $rows,
        ];
    }

    /**
     * @param  Collection<int, string>  $siteIds
     * @return array<string, mixed>
     */
    private function deployments($siteIds): array
    {
        if ($siteIds->isEmpty()) {
            return [
                'failed_count' => 0,
                'recent' => [],
            ];
        }

        $lookbackDays = (int) config('server_health.deployments.lookback_days', 7);
        $since = now()->subDays($lookbackDays);

        $failed = SiteDeployment::query()
            ->with('site:id,name,server_id')
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteDeployment::STATUS_FAILED)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'site_id', 'status', 'created_at']);

        $recent = [];
        foreach ($failed as $deployment) {
            $site = $deployment->site;
            if ($site === null) {
                continue;
            }

            $recent[] = [
                'site_name' => (string) $site->name,
                'at' => $deployment->created_at,
                'href' => route('sites.show', ['server' => $site->server_id, 'site' => $site, 'section' => 'deploy']),
            ];
        }

        return [
            'failed_count' => SiteDeployment::query()
                ->whereIn('site_id', $siteIds)
                ->where('status', SiteDeployment::STATUS_FAILED)
                ->where('created_at', '>=', $since)
                ->count(),
            'recent' => $recent,
            'lookback_days' => $lookbackDays,
        ];
    }

    /**
     * @param  Collection<int, string>  $siteIds
     * @return array<string, mixed>
     */
    private function certificates(Server $server, $siteIds): array
    {
        if ($siteIds->isEmpty()) {
            return [
                'expiring_count' => 0,
                'failed_count' => 0,
                'items' => [],
            ];
        }

        $warningDays = (int) config('server_health.certificates.warning_days', 30);
        $criticalDays = (int) config('server_health.certificates.critical_days', 7);

        $activeStatuses = [
            SiteCertificate::STATUS_ACTIVE,
            SiteCertificate::STATUS_ISSUED,
            SiteCertificate::STATUS_INSTALLING,
        ];

        $certs = SiteCertificate::query()
            ->with('site:id,name,server_id')
            ->whereIn('site_id', $siteIds)
            ->where(function ($query) use ($activeStatuses, $warningDays): void {
                $query->where('status', SiteCertificate::STATUS_FAILED)
                    ->orWhere(function ($inner) use ($activeStatuses, $warningDays): void {
                        $inner->whereIn('status', $activeStatuses)
                            ->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now()->addDays($warningDays));
                    });
            })
            ->orderBy('expires_at')
            ->limit(12)
            ->get(['id', 'site_id', 'status', 'expires_at', 'domains_json']);

        $items = [];
        $expiring = 0;
        $failed = 0;

        foreach ($certs as $cert) {
            $site = $cert->site;
            $domain = is_array($cert->domains_json) ? (string) ($cert->domains_json[0] ?? '') : '';
            $isFailed = $cert->status === SiteCertificate::STATUS_FAILED;

            if ($isFailed) {
                $failed++;
            } elseif ($cert->expires_at !== null) {
                $expiring++;
            }

            $daysLeft = $cert->expires_at !== null
                ? (int) now()->diffInDays($cert->expires_at, false)
                : null;

            $items[] = [
                'site_name' => $site !== null ? (string) $site->name : __('Unknown site'),
                'domain' => $domain,
                'status' => (string) $cert->status,
                'expires_at' => $cert->expires_at,
                'days_left' => $daysLeft,
                'severity' => $isFailed
                    ? 'critical'
                    : ($daysLeft !== null && $daysLeft <= $criticalDays ? 'critical' : 'warning'),
                'href' => $site !== null
                    ? route('sites.show', ['server' => $site->server_id, 'site' => $site, 'section' => 'certificates'])
                    : null,
            ];
        }

        return [
            'expiring_count' => $expiring,
            'failed_count' => $failed,
            'items' => $items,
            'warning_days' => $warningDays,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function daemons(Server $server): array
    {
        $programs = SupervisorProgram::query()
            ->with('site:id,name')
            ->where('server_id', $server->id)
            ->orderBy('slug')
            ->get(['id', 'site_id', 'slug', 'program_type', 'is_active']);

        $inactive = [];
        foreach ($programs as $program) {
            if ($program->is_active) {
                continue;
            }

            $inactive[] = [
                'slug' => (string) $program->slug,
                'type' => (string) $program->program_type,
                'site_name' => $program->site !== null ? (string) $program->site->name : null,
            ];
        }

        return [
            'total' => $programs->count(),
            'inactive_count' => count($inactive),
            'inactive' => $inactive,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function monitoring(Server $server, ?Carbon $capturedAt, array $payload): array
    {
        $staleMinutes = (int) config('server_health.metrics_stale_minutes', 10);
        $stale = $capturedAt === null
            || $capturedAt->lt(now()->subMinutes($staleMinutes));

        return [
            'probe_status' => (string) ($server->health_status ?? 'unknown'),
            'last_checked_at' => $server->last_health_check_at,
            'last_metric_at' => $capturedAt,
            'metrics_stale' => $stale,
            'agent_reporting' => isset($payload['cpu_pct']),
        ];
    }

    /**
     * @param  array<string, mixed>  $capacity
     * @param  list<array<string, mixed>>  $disks
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function capacityAlerts(array $capacity, array $disks, Server $server): array
    {
        $alerts = [];
        $labels = [
            'cpu_pct' => __('CPU'),
            'mem_pct' => __('Memory'),
            'disk_pct' => __('Root disk'),
        ];

        foreach ($labels as $key => $label) {
            $level = $capacity['levels'][$key] ?? 'unknown';
            $value = $capacity['metrics'][$key] ?? null;
            if ($level === 'unknown' || $value === null || $level === 'ok') {
                continue;
            }

            $alerts[] = [
                'severity' => $level,
                'title' => __(':label usage high', ['label' => $label]),
                'message' => __(':label is at :pct% — consider scaling up or offloading sites.', [
                    'label' => $label,
                    'pct' => number_format($value, 1),
                ]),
                'href' => route('servers.monitor', $server),
                'link_label' => __('Open metrics'),
            ];
        }

        $criticalPct = (float) config('server_health.capacity.critical_pct', 90);
        $warningPct = (float) config('server_health.capacity.warning_pct', 75);

        foreach ($disks as $disk) {
            $pct = $disk['pct'] ?? null;
            $mount = (string) ($disk['mount'] ?? '');
            if ($pct === null || $mount === '') {
                continue;
            }
            if ($pct < $warningPct) {
                continue;
            }

            $alerts[] = [
                'severity' => $pct >= $criticalPct ? 'critical' : 'warning',
                'title' => __('Mount :mount is nearly full', ['mount' => $mount]),
                'message' => __(':mount is at :pct% — release dirs and logs often live on data mounts.', [
                    'mount' => $mount,
                    'pct' => number_format($pct, 1),
                ]),
                'href' => route('servers.monitor', $server),
                'link_label' => __('Open metrics'),
            ];
        }

        if (($capacity['headroom'] ?? 'unknown') === 'low') {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Low capacity headroom'),
                'message' => __('This server is running hot — add sites cautiously or plan a larger instance.'),
                'href' => route('servers.monitor', $server),
                'link_label' => __('Review metrics'),
            ];
        }

        return $alerts;
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

        return [[
            'severity' => 'warning',
            'title' => __('Release directories piling up'),
            'message' => trans_choice(
                ':count atomic site has more release folders than its keep setting.|:count atomic sites have extra release folders.',
                (int) $releases['sites_over_keep'],
                ['count' => (int) $releases['sites_over_keep']],
            ),
            'href' => route('servers.health', [$server, 'tab' => 'releases']),
            'link_label' => __('Review releases'),
        ]];
    }

    /**
     * @param  array<string, mixed>  $deployments
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function deploymentAlerts(array $deployments, Server $server): array
    {
        $count = (int) ($deployments['failed_count'] ?? 0);
        if ($count === 0) {
            return [];
        }

        return [[
            'severity' => 'warning',
            'title' => __('Recent deploy failures'),
            'message' => trans_choice(
                ':count failed deploy in the last :days days.|:count failed deploys in the last :days days.',
                $count,
                ['count' => $count, 'days' => (int) ($deployments['lookback_days'] ?? 7)],
            ),
            'href' => route('servers.deploys', $server),
            'link_label' => __('Open deploy history'),
        ]];
    }

    /**
     * @param  array<string, mixed>  $certificates
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function certificateAlerts(array $certificates): array
    {
        $alerts = [];

        if (($certificates['failed_count'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => __('Certificate failures'),
                'message' => trans_choice(
                    ':count certificate failed issuance or install.|:count certificates failed issuance or install.',
                    (int) $certificates['failed_count'],
                    ['count' => (int) $certificates['failed_count']],
                ),
                'href' => null,
                'link_label' => null,
            ];
        }

        if (($certificates['expiring_count'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Certificates expiring soon'),
                'message' => trans_choice(
                    ':count certificate expires within :days days.|:count certificates expire within :days days.',
                    (int) $certificates['expiring_count'],
                    ['count' => (int) $certificates['expiring_count'], 'days' => (int) ($certificates['warning_days'] ?? 30)],
                ),
                'href' => null,
                'link_label' => null,
            ];
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $daemons
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function daemonAlerts(array $daemons, Server $server): array
    {
        if (($daemons['inactive_count'] ?? 0) === 0) {
            return [];
        }

        return [[
            'severity' => 'warning',
            'title' => __('Inactive daemons'),
            'message' => trans_choice(
                ':count supervisor program is marked inactive.|:count supervisor programs are marked inactive.',
                (int) $daemons['inactive_count'],
                ['count' => (int) $daemons['inactive_count']],
            ),
            'href' => route('servers.workers', $server),
            'link_label' => __('Open workers'),
        ]];
    }

    /**
     * @param  array<string, mixed>  $monitoring
     * @return list<array{severity: string, title: string, message: string, href: string|null, link_label: string|null}>
     */
    private function monitoringAlerts(array $monitoring, Server $server): array
    {
        $alerts = [];

        if (($monitoring['metrics_stale'] ?? false) && ($monitoring['agent_reporting'] ?? false)) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => __('Metrics feed is stale'),
                'message' => __('Guest agent samples are older than expected — charts and capacity signals may be outdated.'),
                'href' => route('servers.monitor', $server),
                'link_label' => __('Check monitor agent'),
            ];
        }

        if (($monitoring['probe_status'] ?? '') === Server::HEALTH_UNREACHABLE) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => __('SSH probe unhealthy'),
                'message' => __('Dply cannot reach this server over SSH — deploys and remote actions may fail.'),
                'href' => route('servers.monitor', $server),
                'link_label' => __('Run probe'),
            ];
        }

        return $alerts;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function metricFloat(array $payload, string $key): ?float
    {
        if (! array_key_exists($key, $payload)) {
            return null;
        }

        return is_numeric($payload[$key]) ? (float) $payload[$key] : null;
    }
}
