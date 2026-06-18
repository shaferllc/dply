<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\Site;

/**
 * Resolve a site's public base URL (https://host) from its primary customer
 * domain, falling back to the dply testing hostname. Used by bindings that need
 * to auto-fill an external URL — the OAuth redirect URI and the payments
 * webhook endpoint — from the site the operator is configuring.
 */
trait ResolvesSitePublicUrl
{
    /**
     * The site's public base URL (no trailing slash), or null when the site has
     * no customer domain or testing hostname yet.
     */
    private function siteBaseUrl(Site $site): ?string
    {
        $host = $site->primaryDomain()?->hostname;
        if (! is_string($host) || trim($host) === '') {
            $host = $site->testingHostname();
        }

        $host = strtolower(trim((string) $host));

        return $host !== '' ? 'https://'.$host : null;
    }
}
