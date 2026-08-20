<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteUptimeMonitor;

/**
 * Seeds the default homepage HTTP + HTTPS monitor pair and backfills the
 * legacy single "Homepage check" (http, but it actually probed HTTPS).
 *
 * Used by Site::created and the site Monitor page so both paths stay in sync.
 * `probe_region` is the host-nearest region (cosmetic); `probe_worker` still
 * routes the check onto that worker's queue.
 */
final class EnsuresDefaultUptimeMonitors
{
    public function __construct(
        private readonly UptimeProbeRegionResolver $regions,
        private readonly UptimeProbeWorkerResolver $workers,
    ) {}

    /**
     * @return list<SiteUptimeMonitor> newly created monitors
     */
    public function ensure(Site $site): array
    {
        $configured = array_keys((array) config('site_uptime.probe_regions', []));
        if ($configured === []) {
            return [];
        }

        $site->loadMissing('server', 'uptimeMonitors');

        $region = $this->regions->forSite($site);
        $worker = $this->workers->forSite($site);

        $legacy = $this->legacyHomepage($site);
        if ($legacy !== null) {
            $legacy->update([
                'label' => __('Homepage (HTTPS)'),
                'check_type' => SiteUptimeMonitor::CHECK_HTTPS,
                'probe_region' => $region,
                'probe_worker' => $worker ?? $legacy->probe_worker,
            ]);
            $site->unsetRelation('uptimeMonitors');
        }

        if (! $this->shouldSeedPair($site, $legacy !== null)) {
            $this->syncHostRegion($site, $region, $worker);

            return [];
        }

        $created = [];

        $https = $this->firstOrCreateHomepage(
            $site,
            __('Homepage (HTTPS)'),
            SiteUptimeMonitor::CHECK_HTTPS,
            0,
            $region,
            $worker,
        );
        if ($https->wasRecentlyCreated) {
            $created[] = $https;
        }

        $http = $this->firstOrCreateHomepage(
            $site,
            __('Homepage (HTTP)'),
            SiteUptimeMonitor::CHECK_HTTP,
            1,
            $region,
            $worker,
        );
        if ($http->wasRecentlyCreated) {
            $created[] = $http;
        }

        $this->syncHostRegion($site, $region, $worker);

        return $created;
    }

    private function shouldSeedPair(Site $site, bool $convertedLegacy): bool
    {
        if ($convertedLegacy) {
            return true;
        }

        $site->loadMissing('uptimeMonitors');

        if ($site->uptimeMonitors->isEmpty()) {
            return true;
        }

        return $site->uptimeMonitors->contains(
            fn (SiteUptimeMonitor $monitor): bool => in_array($monitor->label, $this->defaultPairLabels(), true),
        );
    }

    /** @return list<string> */
    private function defaultPairLabels(): array
    {
        return [
            __('Homepage (HTTPS)'),
            __('Homepage (HTTP)'),
            'Homepage (HTTPS)',
            'Homepage (HTTP)',
        ];
    }

    private function legacyHomepage(Site $site): ?SiteUptimeMonitor
    {
        $labels = array_values(array_unique([__('Homepage check'), 'Homepage check']));

        return $site->uptimeMonitors->first(
            fn (SiteUptimeMonitor $monitor): bool => in_array($monitor->label, $labels, true)
                && ($monitor->check_type ?? SiteUptimeMonitor::CHECK_HTTP) === SiteUptimeMonitor::CHECK_HTTP,
        );
    }

    private function firstOrCreateHomepage(
        Site $site,
        string $label,
        string $checkType,
        int $sortOrder,
        string $region,
        ?string $worker,
    ): SiteUptimeMonitor {
        $existing = $site->uptimeMonitors->first(
            fn (SiteUptimeMonitor $monitor): bool => $monitor->label === $label,
        );
        if ($existing !== null) {
            return $existing;
        }

        $maxSort = (int) $site->uptimeMonitors->max('sort_order');
        $sort = $site->uptimeMonitors->firstWhere('sort_order', $sortOrder) === null
            ? $sortOrder
            : $maxSort + 1;

        $monitor = SiteUptimeMonitor::query()->create([
            'site_id' => $site->id,
            'label' => $label,
            'check_type' => $checkType,
            'path' => null,
            'probe_region' => $region,
            'probe_worker' => $worker,
            'sort_order' => $sort,
        ]);

        $site->unsetRelation('uptimeMonitors');
        $site->load('uptimeMonitors');

        return $monitor;
    }

    private function syncHostRegion(Site $site, string $region, ?string $worker): void
    {
        $site->load('uptimeMonitors');

        foreach ($site->uptimeMonitors as $monitor) {
            $updates = [];
            if ($monitor->probe_region !== $region) {
                $updates['probe_region'] = $region;
            }
            if ($monitor->probe_worker === null && $worker !== null) {
                $updates['probe_worker'] = $worker;
            }
            if ($updates !== []) {
                $monitor->update($updates);
            }
        }
    }
}
