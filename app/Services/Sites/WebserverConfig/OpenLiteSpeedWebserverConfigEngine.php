<?php

namespace App\Services\Sites\WebserverConfig;

use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;

class OpenLiteSpeedWebserverConfigEngine implements WebserverConfigEngineInterface
{
    public function __construct(
        private readonly OpenLiteSpeedSiteConfigBuilder $builder,
    ) {}

    public function webserver(): string
    {
        return 'openlitespeed';
    }

    public function effectiveConfig(Site $site, ?SiteWebserverConfigProfile $profile): string
    {
        if ($profile && $profile->isFullOverride() && trim((string) $profile->full_override_body) !== '') {
            return trim((string) $profile->full_override_body);
        }

        return $this->builder->build($site);
    }

    public function managedCoreHash(Site $site): string
    {
        return hash('sha256', $this->builder->build($site));
    }

    public function validateLocal(string $config): array
    {
        return [
            'ok' => true,
            'message' => __('OpenLiteSpeed validation runs on the server during Apply.'),
        ];
    }

    public function validateRemote(Site $site, string $config, ?SiteWebserverConfigProfile $profile): array
    {
        return [
            'ok' => true,
            'message' => __('Remote dry-run for OpenLiteSpeed is not available. Apply restarts the web server with the new vhost.'),
        ];
    }
}
