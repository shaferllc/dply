<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\SiteDeployment;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

/**
 * Correlates deploy activity and server metrics into shared-host contention events.
 */
final class HostContentionDetector
{
    public function __construct(
        private SiteLoadAttributor $attributor,
        private SharedHostBudgetEvaluator $budgetEvaluator,
    ) {}

    /**
     * @param  array<string, mixed>|null  $attribution
     * @param  array<string, mixed>|null  $budgetSettings
     * @return list<array<string, mixed>>
     */
    public function events(Server $server, ?array $attribution = null, ?array $budgetSettings = null): array
    {
        $server->loadMissing('sites');
        if ($server->sites->count() < 2) {
            return [];
        }

        $maxEvents = (int) config('server_shared_host.contention.max_events', 7);
        $attribution ??= $this->attributor->forServer($server);

        $events = array_merge(
            $this->deployCpuSpikeEvents($server),
            $this->dominantSiteEvents($server, $attribution),
            $this->budgetBreachEvents($server, $attribution, $budgetSettings),
        );

        usort($events, static fn (array $a, array $b): int => ($b['occurred_at'] ?? now()) <=> ($a['occurred_at'] ?? now()));

        return array_slice($events, 0, max(1, $maxEvents));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deployCpuSpikeEvents(Server $server): array
    {
        $siteIds = $server->sites->pluck('id')->all();
        if ($siteIds === []) {
            return [];
        }

        $since = now()->subDays(7);
        $cpuThreshold = (float) config('server_shared_host.contention.cpu_spike_pct', 85);
        $windowMinutes = (int) config('server_shared_host.contention.deploy_correlation_minutes', 15);

        $deployments = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('started_at', '>=', $since)
            ->whereNotNull('started_at')
            ->with('site:id,slug,name,server_id')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();

        $events = [];

        foreach ($deployments as $deployment) {
            $startedAt = $deployment->started_at;
            if (! $startedAt instanceof Carbon) {
                continue;
            }

            $endAt = ($deployment->finished_at instanceof Carbon ? $deployment->finished_at : $startedAt)
                ->copy()
                ->addMinutes(max(1, $windowMinutes));

            $peakCpu = ServerMetricSnapshot::query()
                ->where('server_id', $server->id)
                ->whereBetween('captured_at', [$startedAt, $endAt])
                ->get(['payload', 'captured_at'])
                ->map(static fn (ServerMetricSnapshot $snapshot): float => (float) ($snapshot->payload['cpu_pct'] ?? 0))
                ->max();

            if ($peakCpu === null || $peakCpu < $cpuThreshold) {
                continue;
            }

            $site = $deployment->site;
            $siteName = $site !== null ? (string) $site->name : __('Unknown site');
            $siteSlug = $site !== null ? (string) $site->slug : null;

            $events[] = [
                'id' => 'deploy-cpu-'.$deployment->id,
                'kind' => 'deploy_cpu',
                'severity' => $peakCpu >= 95 ? 'critical' : 'warning',
                'title' => __('Deploy correlated with CPU spike'),
                'message' => __(':site deploy raised host CPU to :cpu% within :minutes minutes — other sites on this server may have slowed down.', [
                    'site' => $siteName,
                    'cpu' => number_format($peakCpu, 0),
                    'minutes' => $windowMinutes,
                ]),
                'occurred_at' => $startedAt,
                'site_slug' => $siteSlug,
                'site_name' => $siteName,
                'site_href' => $site !== null ? route('sites.show', ['server' => $server, 'site' => $site]) : null,
                'action_label' => __('Open deploys'),
                'action_route' => $site !== null ? 'sites.deployments' : 'servers.sites',
                'action_params' => $site !== null ? ['server' => $server, 'site' => $site] : ['server' => $server],
                'secondary_actions' => $this->secondaryActions($server),
            ];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @return list<array<string, mixed>>
     */
    private function dominantSiteEvents(Server $server, array $attribution): array
    {
        if (! ($attribution['has_snapshot'] ?? false)) {
            return [];
        }

        $dominantPct = (float) config('server_shared_host.contention.dominant_site_pct', 70);
        $rows = is_array($attribution['rows'] ?? null) ? $attribution['rows'] : [];
        $checkedAt = $attribution['checked_at'] ?? null;
        $occurredAt = $checkedAt instanceof Carbon ? $checkedAt : now();

        $events = [];

        foreach ($rows as $row) {
            $cpuShare = $row['cpu_share_pct'] ?? null;
            $memShare = $row['mem_share_pct'] ?? null;
            $cpuPct = (float) ($row['cpu_pct'] ?? 0);
            $memMb = (float) ($row['mem_mb'] ?? 0);

            $dominantCpu = $cpuShare !== null && $cpuShare >= $dominantPct && $cpuPct >= 10;
            $dominantMem = $memShare !== null && $memShare >= $dominantPct && $memMb >= 128;

            if (! $dominantCpu && ! $dominantMem) {
                continue;
            }

            $siteName = (string) ($row['name'] ?? $row['slug'] ?? __('Site'));

            $events[] = [
                'id' => 'dominant-'.(string) ($row['slug'] ?? 'site'),
                'kind' => 'dominant_site',
                'severity' => 'warning',
                'title' => __('Noisy neighbor detected'),
                'message' => $dominantCpu
                    ? __(':site is using :share% of attributable CPU on this host — consider moving it to a standby server.', [
                        'site' => $siteName,
                        'share' => number_format((float) $cpuShare, 0),
                    ])
                    : __(':site is using :share% of attributable memory on this host — consider moving it to a standby server.', [
                        'site' => $siteName,
                        'share' => number_format((float) $memShare, 0),
                    ]),
                'occurred_at' => $occurredAt,
                'site_slug' => (string) ($row['slug'] ?? ''),
                'site_name' => $siteName,
                'site_href' => (string) ($row['href'] ?? route('servers.sites', $server)),
                'action_label' => Feature::active('workspace.site_promote') ? __('Promote to standby') : __('Open site'),
                'action_route' => Feature::active('workspace.site_promote') ? 'sites.promote' : 'sites.show',
                'action_params' => $this->promoteParams($server, (string) ($row['slug'] ?? '')),
                'secondary_actions' => array_merge($this->secondaryActions($server), [
                    [
                        'label' => __('Maintenance'),
                        'route' => 'servers.maintenance',
                        'params' => ['server' => $server],
                    ],
                ]),
            ];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @param  array<string, mixed>|null  $budgetSettings
     * @return list<array<string, mixed>>
     */
    private function budgetBreachEvents(Server $server, array $attribution, ?array $budgetSettings): array
    {
        if ($budgetSettings !== null && ! ($budgetSettings['alerts_enabled'] ?? true)) {
            return [];
        }

        $rows = is_array($attribution['rows'] ?? null) ? $attribution['rows'] : [];
        $usePeak = ($attribution['range'] ?? 'current') !== 'current';
        $breaches = $this->budgetEvaluator->breaches($server, $rows, $usePeak);
        $events = [];

        foreach ($breaches as $breach) {
            $events[] = [
                'id' => (string) ($breach['id'] ?? uniqid('budget-', true)),
                'kind' => 'budget',
                'severity' => (string) ($breach['severity'] ?? 'warning'),
                'title' => (string) ($breach['title'] ?? __('Soft budget exceeded')),
                'message' => (string) ($breach['message'] ?? ''),
                'occurred_at' => now(),
                'site_slug' => (string) ($breach['slug'] ?? ''),
                'site_name' => (string) ($breach['name'] ?? ''),
                'site_href' => route('servers.shared-host', $server).'#budgets',
                'action_label' => __('Adjust budgets'),
                'action_route' => 'servers.shared-host',
                'action_params' => ['server' => $server],
                'secondary_actions' => $this->secondaryActions($server),
            ];
        }

        return $events;
    }

    /**
     * @return list<array{label: string, route: string, params: array<string, mixed>}>
     */
    private function secondaryActions(Server $server): array
    {
        $actions = [
            [
                'label' => __('Cron jobs'),
                'route' => 'servers.cron',
                'params' => ['server' => $server],
            ],
        ];

        if (Feature::active('workspace.server_cost')) {
            $actions[] = [
                'label' => __('Cost & right-size'),
                'route' => 'servers.cost',
                'params' => ['server' => $server],
            ];
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>
     */
    private function promoteParams(Server $server, string $slug): array
    {
        $site = $server->sites->firstWhere('slug', $slug);
        if ($site === null) {
            return ['server' => $server];
        }

        return ['server' => $server, 'site' => $site];
    }
}
