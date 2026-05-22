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
     * Best-effort public URL for the site (no path). Primary domain →
     * serverless function host → preview/testing → runtime publication.
     */
    public function resolveBaseUrl(Site $site): ?string
    {
        $site->loadMissing('domains', 'previewDomains');

        $primary = $site->primaryDomain();
        if ($primary && is_string($primary->hostname) && trim($primary->hostname) !== '') {
            $host = strtolower(trim($primary->hostname));

            return 'https://'.$host;
        }

        // A serverless function publishes at its friendly hostname (and,
        // failing that, its raw DigitalOcean Functions invocation URL) —
        // neither is a `domains` row, so the checks above miss it.
        if ($site->usesFunctionsRuntime()) {
            $functionHost = $site->serverlessFunctionHost();
            if (is_string($functionHost) && trim($functionHost) !== '') {
                return 'https://'.strtolower(trim($functionHost));
            }

            $actionUrl = trim((string) ($site->serverlessConfig()['action_url'] ?? ''));
            if ($actionUrl !== '') {
                return rtrim($actionUrl, '/');
            }
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
