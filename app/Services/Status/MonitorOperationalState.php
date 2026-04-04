<?php

namespace App\Services\Status;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteUptimeMonitor;

class MonitorOperationalState
{
    public const OPERATIONAL = 'operational';

    public const DEGRADED = 'degraded';

    public const OUTAGE = 'outage';

    public const UNKNOWN = 'unknown';

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN
     */
    public function state(Server|Site|SiteUptimeMonitor $model): string
    {
        if ($model instanceof SiteUptimeMonitor) {
            return $this->stateForSiteUptimeMonitor($model);
        }

        if ($model instanceof Site) {
            return $this->stateForSite($model);
        }

        return $this->stateForServer($model);
    }

    public function label(string $state): string
    {
        return match ($state) {
            self::OPERATIONAL => __('Operational'),
            self::DEGRADED => __('Degraded'),
            self::OUTAGE => __('Outage'),
            default => __('Unknown'),
        };
    }

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN
     */
    private function stateForSiteUptimeMonitor(SiteUptimeMonitor $monitor): string
    {
        $checked = $monitor->last_checked_at;
        if ($checked === null) {
            return self::UNKNOWN;
        }

        $minutes = max(1, (int) config('site_uptime.check_interval_minutes', 5));
        $mult = max(1, (int) config('site_uptime.stale_check_multiplier', 2));
        $staleAfterMinutes = $minutes * $mult;

        if ($checked->lt(now()->subMinutes($staleAfterMinutes))) {
            return self::UNKNOWN;
        }

        if ($monitor->last_ok) {
            return self::OPERATIONAL;
        }

        return self::OUTAGE;
    }

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN
     */
    private function stateForServer(Server $server): string
    {
        if ($server->health_status === Server::HEALTH_REACHABLE) {
            return self::OPERATIONAL;
        }
        if ($server->health_status === Server::HEALTH_UNREACHABLE) {
            return self::OUTAGE;
        }

        return self::UNKNOWN;
    }

    /**
     * @return self::OPERATIONAL|self::DEGRADED|self::OUTAGE|self::UNKNOWN
     */
    private function stateForSite(Site $site): string
    {
        $server = $site->relationLoaded('server') ? $site->server : $site->server()->first();
        if (! $server instanceof Server) {
            return self::UNKNOWN;
        }

        if ($server->health_status === Server::HEALTH_UNREACHABLE) {
            return self::OUTAGE;
        }

        if ($site->status === Site::STATUS_ERROR) {
            return self::DEGRADED;
        }

        if ($site->isReadyForTraffic() && $server->health_status === Server::HEALTH_REACHABLE) {
            return self::OPERATIONAL;
        }

        if ($server->health_status === Server::HEALTH_REACHABLE) {
            return self::DEGRADED;
        }

        return self::UNKNOWN;
    }
}
