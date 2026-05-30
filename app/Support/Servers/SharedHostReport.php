<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Laravel\Pennant\Feature;

/**
 * Aggregates attribution, shared stack map, and contention events for Shared Host Radar.
 */
final class SharedHostReport
{
    public function __construct(
        private SiteLoadAttributor $attributor,
        private SharedStackMapBuilder $stackMap,
        private HostContentionDetector $contention,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forServer(Server $server): array
    {
        $attribution = $this->attributor->forServer($server);
        $sharedMap = $this->stackMap->forServer($server);
        $events = $this->contention->events($server, $attribution);

        $overall = $this->resolveOverall($attribution, $events, $sharedMap);

        return [
            'overall' => $overall,
            'solo_tenant' => (bool) ($attribution['solo_tenant'] ?? true),
            'site_count' => (int) ($attribution['site_count'] ?? 0),
            'attribution' => $attribution,
            'shared_map' => $sharedMap,
            'contention_events' => $events,
            'contention_count' => count($events),
            'promote_enabled' => Feature::active('workspace.site_promote'),
            'summary' => [
                'shared_resource_count' => count($sharedMap['shared_resources'] ?? []),
                'dominant_site' => $this->dominantSite($attribution),
                'latest_event_at' => $events[0]['occurred_at'] ?? null,
            ],
        ];
    }

    /**
     * Compact card for server overview when multi-site contention is present.
     *
     * @return array{title: string, severity: string, message: string, event_count: int}|null
     */
    public function overviewSummary(Server $server): ?array
    {
        if (! $server->isVmHost() || $server->isManagedProductHost()) {
            return null;
        }

        $report = $this->forServer($server);
        if ($report['solo_tenant'] ?? true) {
            return null;
        }

        $eventCount = (int) ($report['contention_count'] ?? 0);
        if ($eventCount === 0 && ($report['shared_map']['shared_resources'] ?? []) === []) {
            return null;
        }

        $overall = (string) ($report['overall'] ?? 'ok');

        return [
            'title' => $eventCount > 0
                ? trans_choice(':count contention event on this host|:count contention events on this host', $eventCount, ['count' => $eventCount])
                : __('Shared resources detected'),
            'severity' => $overall === 'critical' ? 'critical' : ($overall === 'warning' ? 'warning' : 'info'),
            'message' => $eventCount > 0
                ? __('Review site load and shared dependencies before the next deploy.')
                : __('Multiple sites share stack resources on this server.'),
            'event_count' => $eventCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $sharedMap
     */
    private function resolveOverall(array $attribution, array $events, array $sharedMap): string
    {
        foreach ($events as $event) {
            if (($event['severity'] ?? '') === 'critical') {
                return 'critical';
            }
        }

        if ($events !== []) {
            return 'warning';
        }

        if (($attribution['stale'] ?? false) && ! ($attribution['solo_tenant'] ?? true)) {
            return 'info';
        }

        if (($sharedMap['shared_resources'] ?? []) !== []) {
            return 'info';
        }

        return 'ok';
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @return array{slug: string, name: string, cpu_share_pct: ?float, mem_share_pct: ?float}|null
     */
    private function dominantSite(array $attribution): ?array
    {
        $rows = is_array($attribution['rows'] ?? null) ? $attribution['rows'] : [];
        if ($rows === []) {
            return null;
        }

        $top = $rows[0];

        return [
            'slug' => (string) ($top['slug'] ?? ''),
            'name' => (string) ($top['name'] ?? ''),
            'cpu_share_pct' => $top['cpu_share_pct'] ?? null,
            'mem_share_pct' => $top['mem_share_pct'] ?? null,
        ];
    }
}
