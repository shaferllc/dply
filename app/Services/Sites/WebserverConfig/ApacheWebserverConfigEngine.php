<?php

namespace App\Services\Sites\WebserverConfig;

use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\SiteApacheProvisioner;

class ApacheWebserverConfigEngine implements WebserverConfigEngineInterface
{
    public function __construct(
        private readonly ApacheSiteConfigBuilder $builder,
        private readonly SiteApacheProvisioner $provisioner,
    ) {}

    public function webserver(): string
    {
        return 'apache';
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
            'message' => __('Local Apache syntax checking is not available in the control plane. Use “Validate on server”.'),
        ];
    }

    public function validateRemote(Site $site, string $config, ?SiteWebserverConfigProfile $profile): array
    {
        return $this->provisioner->validatePendingOnServer($site, $config);
    }
}
