<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

final class VmDockerSiteConfigSupport
{
    public static function applies(Site $site): bool
    {
        return $site->usesVmDockerRuntime();
    }

    public static function upstreamPort(Site $site): int
    {
        $publicationPort = data_get($site->meta, 'runtime_target.publication.port');
        if (is_numeric($publicationPort) && (int) $publicationPort > 0) {
            return (int) $publicationPort;
        }

        if ($site->internal_port !== null && $site->internal_port > 0) {
            return (int) $site->internal_port;
        }

        return 30000;
    }
}
