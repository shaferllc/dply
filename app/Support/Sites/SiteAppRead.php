<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Why dply cannot read a site's app on its server right now, or null when it can.
 *
 * The on-demand reads behind the queue page — failed jobs, waiting jobs, one
 * job's payload, the job-class catalogue — cache this as their answer instead
 * of returning silently. The page polls until something is cached, so a silent
 * return used to leave it saying "reading…" forever.
 */
final class SiteAppRead
{
    public static function blocker(Site $site): ?string
    {
        if ($site->server === null || ! $site->server->isReady()) {
            return __('The server isn’t ready yet, so dply can’t read this site.');
        }

        if (rtrim((string) $site->effectiveEnvDirectory(), '/') === '') {
            return __('This site has no app directory on the server to read.');
        }

        return null;
    }
}
