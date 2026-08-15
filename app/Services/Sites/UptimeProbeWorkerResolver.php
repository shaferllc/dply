<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Resolves which real probe worker a monitor runs on, and the Horizon queue a
 * check should be dispatched onto. Worker selection is host-aware: it picks the
 * configured worker nearest the site's host region (reusing
 * UptimeProbeRegionResolver's proximity map) and falls back to the first
 * configured worker when the nearest region has no deployed box. With a single
 * configured worker, proximity is moot and that worker is always chosen.
 *
 * "Configured" == present in `site_uptime.probe_workers`. There is no liveness
 * detection in v1: a worker is assumed live the moment its config entry exists,
 * which you add when the box is actually deployed.
 */
final class UptimeProbeWorkerResolver
{
    /** Queue used when a monitor has no (or an unknown) worker — central egress. */
    public const FALLBACK_QUEUE = 'default';

    public function __construct(private readonly UptimeProbeRegionResolver $regions) {}

    /**
     * Nearest configured worker key for the site's host; first configured as a
     * fallback; null when no workers are configured at all (feature dormant).
     */
    public function forSite(Site $site): ?string
    {
        $workers = $this->workers();
        if ($workers === []) {
            return null;
        }

        $preferredRegion = $this->regions->forSite($site);
        foreach ($workers as $key => $worker) {
            if (($worker['region'] ?? null) === $preferredRegion) {
                return (string) $key;
            }
        }

        return (string) array_key_first($workers);
    }

    /**
     * Horizon queue a check for this worker should run on. Falls back to the
     * central `default` queue when the worker is null or no longer configured,
     * so a monitor never silently stops being checked.
     */
    public function queueFor(?string $workerKey): string
    {
        $queue = $workerKey !== null ? ($this->workers()[$workerKey]['queue'] ?? null) : null;

        return is_string($queue) && $queue !== '' ? $queue : self::FALLBACK_QUEUE;
    }

    /** The `probe_regions` key for a worker, used to derive the cosmetic label. */
    public function regionFor(?string $workerKey): ?string
    {
        $region = $workerKey !== null ? ($this->workers()[$workerKey]['region'] ?? null) : null;

        return is_string($region) && $region !== '' ? $region : null;
    }

    /**
     * Worker dropdown options: worker key → display label (from probe_regions).
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $regions = (array) config('site_uptime.probe_regions', []);

        $options = [];
        foreach ($this->workers() as $key => $worker) {
            $region = $worker['region'] ?? null;
            $options[(string) $key] = (string) ($regions[$region] ?? $key);
        }

        return $options;
    }

    /** @return array<string, array<string, mixed>> */
    private function workers(): array
    {
        return (array) config('site_uptime.probe_workers', []);
    }
}
