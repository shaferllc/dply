<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\Site;
use Laravel\Pennant\Feature;

/**
 * Rule-based fairness recommendations for Shared Host Radar.
 */
final class SharedHostFairnessAdvisor
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{
     *     summary: string,
     *     severity: string,
     *     recommendations: list<array{
     *         id: string,
     *         title: string,
     *         summary: string,
     *         confidence: string,
     *         actions: list<array{label: string, url: string}>
     *     }>
     * }
     */
    public function advise(Server $server, array $report): array
    {
        if ((bool) ($report['solo_tenant'] ?? true)) {
            return [
                'summary' => __('Shared Host Fairness Advisor activates when two or more sites share this server.'),
                'severity' => 'info',
                'recommendations' => [],
            ];
        }

        $recommendations = [];
        $events = is_array($report['contention_events'] ?? null) ? $report['contention_events'] : [];
        $sharedMap = is_array($report['shared_map'] ?? null) ? $report['shared_map'] : [];
        $breaches = is_array($report['budget_breaches'] ?? null) ? $report['budget_breaches'] : [];
        $dominant = is_array($report['summary']['dominant_site'] ?? null) ? $report['summary']['dominant_site'] : null;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $recommendations = array_merge(
                $recommendations,
                $this->recommendationsForEvent($server, $event),
            );
        }

        if ($recommendations === [] && $dominant !== null) {
            $recommendations[] = $this->dominantSiteRecommendation($server, $dominant);
        }

        foreach ($breaches as $breach) {
            if (! is_array($breach)) {
                continue;
            }
            $recommendations[] = $this->budgetRecommendation($server, $breach);
        }

        $sharedResources = is_array($sharedMap['shared_resources'] ?? null) ? $sharedMap['shared_resources'] : [];
        if ($sharedResources !== []) {
            $recommendations[] = $this->sharedStackRecommendation($server, $sharedResources);
        }

        $recommendations = $this->dedupeRecommendations($recommendations);
        $severity = $this->resolveSeverity($report, $recommendations);

        return [
            'summary' => $this->buildSummary($server, $report, $recommendations),
            'severity' => $severity,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<array<string, mixed>>
     */
    private function recommendationsForEvent(Server $server, array $event): array
    {
        $kind = (string) ($event['kind'] ?? '');
        $siteSlug = (string) ($event['site_slug'] ?? '');
        $site = $siteSlug !== '' ? $server->sites->firstWhere('slug', $siteSlug) : null;

        return match ($kind) {
            'dominant_site' => [$this->noisyNeighborRecommendation($server, $event, $site)],
            'deploy_cpu' => [$this->deploySpikeRecommendation($server, $event, $site)],
            'budget' => [$this->budgetRecommendation($server, $event)],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function noisyNeighborRecommendation(Server $server, array $event, ?Site $site): array
    {
        $siteName = (string) ($event['site_name'] ?? __('A site'));
        $actions = [];

        if ($site !== null) {
            $actions[] = [
                'label' => __('Open monitor'),
                'url' => route('sites.monitor', ['server' => $server, 'site' => $site]),
            ];
            $actions[] = [
                'label' => __('Queue workers'),
                'url' => route('sites.show', ['server' => $server, 'site' => $site]).'?section=workers',
            ];
        }

        if (Feature::active('workspace.site_promote') && $site !== null) {
            $actions[] = [
                'label' => __('Promote to standby'),
                'url' => route('sites.promote', ['server' => $server, 'site' => $site]),
            ];
        }

        $actions[] = [
            'label' => __('Server cron'),
            'url' => route('servers.cron', $server),
        ];

        return [
            'id' => 'noisy-neighbor-'.($site->slug ?? 'site'),
            'title' => __('Noisy neighbor — :site', ['site' => $siteName]),
            'summary' => (string) ($event['message'] ?? __('One site is consuming a disproportionate share of CPU or memory.')),
            'confidence' => 'high',
            'actions' => $actions,
        ];
    }

    /**
     * @param  array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function deploySpikeRecommendation(Server $server, array $event, ?Site $site): array
    {
        $siteName = (string) ($event['site_name'] ?? __('A site'));
        $actions = [
            [
                'label' => __('Stagger cron jobs'),
                'url' => route('servers.cron', $server),
            ],
            [
                'label' => __('Shared Host budgets'),
                'url' => route('servers.shared-host', $server).'#budgets',
            ],
        ];

        if ($site !== null) {
            $actions[] = [
                'label' => __('Site deploys'),
                'url' => route('sites.deployments', ['server' => $server, 'site' => $site]),
            ];
        }

        return [
            'id' => 'deploy-spike-'.($site->slug ?? 'site'),
            'title' => __('Deploy spike — :site', ['site' => $siteName]),
            'summary' => (string) ($event['message'] ?? __('A recent deploy correlated with elevated host CPU — other sites may have slowed down.')),
            'confidence' => 'high',
            'actions' => $actions,
        ];
    }

    /**
     * @param  array<string, mixed> $breach
     * @return array<string, mixed>
     */
    private function budgetRecommendation(Server $server, array $breach): array
    {
        return [
            'id' => 'budget-'.(string) ($breach['slug'] ?? 'site'),
            'title' => (string) ($breach['title'] ?? __('Soft budget exceeded')),
            'summary' => (string) ($breach['message'] ?? __('A site exceeded its CPU or memory budget on this shared host.')),
            'confidence' => 'high',
            'actions' => [
                [
                    'label' => __('Adjust budgets'),
                    'url' => route('servers.shared-host', $server).'#budgets',
                ],
                [
                    'label' => __('Notification settings'),
                    'url' => route('profile.notification-channels'),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed> $dominant
     * @return array<string, mixed>
     */
    private function dominantSiteRecommendation(Server $server, array $dominant): array
    {
        $slug = (string) ($dominant['slug'] ?? '');
        $site = $slug !== '' ? $server->sites->firstWhere('slug', $slug) : null;
        $name = (string) ($dominant['name'] ?? $slug);
        $cpuShare = isset($dominant['cpu_share_pct']) ? number_format((float) $dominant['cpu_share_pct'], 0).'%' : null;

        $actions = [];
        if ($site !== null) {
            $actions[] = [
                'label' => __('Open monitor'),
                'url' => route('sites.monitor', ['server' => $server, 'site' => $site]),
            ];
        }

        return [
            'id' => 'dominant-'.$slug,
            'title' => __('Dominant load — :site', ['site' => $name]),
            'summary' => $cpuShare !== null
                ? __(':site leads attributable CPU at :share. Review workers and deploy windows before adding more sites.', [
                    'site' => $name,
                    'share' => $cpuShare,
                ])
                : __(':site is the current load leader on this host.', ['site' => $name]),
            'confidence' => 'medium',
            'actions' => $actions,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sharedResources
     * @return array<string, mixed>
     */
    private function sharedStackRecommendation(Server $server, array $sharedResources): array
    {
        $labels = collect($sharedResources)
            ->map(static fn (array $row): string => (string) ($row['label'] ?? $row['type'] ?? 'resource'))
            ->filter()
            ->unique()
            ->take(3)
            ->implode(', ');

        return [
            'id' => 'shared-stack',
            'title' => __('Shared stack dependencies'),
            'summary' => __('Sites on this server share: :resources. A spike or outage on one dependency affects every bound site.', [
                'resources' => $labels !== '' ? $labels : __('Redis or database services'),
            ]),
            'confidence' => 'medium',
            'actions' => [
                [
                    'label' => __('Caches'),
                    'url' => route('servers.caches', $server),
                ],
                [
                    'label' => __('Databases'),
                    'url' => route('servers.databases', $server),
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @return list<array<string, mixed>>
     */
    private function dedupeRecommendations(array $recommendations): array
    {
        $seen = [];
        $unique = [];

        foreach ($recommendations as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $row;
        }

        return array_slice($unique, 0, 6);
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @param  array<string, mixed> $report
     */
    private function buildSummary(Server $server, array $report, array $recommendations): string
    {
        $siteCount = (int) ($report['site_count'] ?? 0);
        $eventCount = (int) ($report['contention_count'] ?? 0);
        $overall = (string) ($report['overall'] ?? 'ok');

        if ($recommendations === []) {
            return __(':count sites share this host with balanced headroom. Keep attribution scans on a schedule.', [
                'count' => $siteCount,
            ]);
        }

        if ($overall === 'critical' || $eventCount >= 2) {
            return __(':count sites share this host and :events contention signals need attention. Start with the highest-confidence recommendation below.', [
                'count' => $siteCount,
                'events' => $eventCount,
            ]);
        }

        return __(':count sites share this host. Review the recommendations below to reduce noisy-neighbor risk on :server.', [
            'count' => $siteCount,
            'server' => $server->name,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @param  array<string, mixed> $report
     */
    private function resolveSeverity(array $report, array $recommendations): string
    {
        if ($recommendations === []) {
            return 'info';
        }

        $overall = (string) ($report['overall'] ?? 'ok');

        return in_array($overall, ['critical', 'warning'], true) ? $overall : 'warning';
    }
}
