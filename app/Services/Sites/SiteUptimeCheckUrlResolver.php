<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteUptimeMonitor;

class SiteUptimeCheckUrlResolver
{
    /**
     * Full URL for an HTTP GET check (scheme + host + monitor path).
     */
    public function resolveFullUrl(Site $site, SiteUptimeMonitor $monitor): ?string
    {
        $base = $this->resolveBaseUrl($site);
        if ($base === null) {
            return null;
        }

        $path = $monitor->normalizedPath();
        if ($path === '') {
            return $base;
        }

        return rtrim($base, '/').$path;
    }

    /**
     * Best-effort public URL for the site (no path). Primary domain → preview/testing → runtime publication.
     */
    public function resolveBaseUrl(Site $site): ?string
    {
        $site->loadMissing('domains', 'previewDomains');

        $primary = $site->primaryDomain();
        if ($primary && is_string($primary->hostname) && trim($primary->hostname) !== '') {
            $host = strtolower(trim($primary->hostname));

            return 'https://'.$host;
        }

        $testing = $site->testingHostname();
        if ($testing !== '') {
            return 'https://'.strtolower(trim($testing));
        }

        $target = $site->runtimeTarget();
        $publication = is_array($target['publication'] ?? null) ? $target['publication'] : [];
        $url = $publication['url'] ?? null;
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            return rtrim($url, '/');
        }

        $hostname = isset($publication['hostname']) && is_string($publication['hostname'])
            ? trim($publication['hostname'])
            : '';
        if ($hostname !== '') {
            return 'http://'.strtolower($hostname);
        }

        return null;
    }
}
