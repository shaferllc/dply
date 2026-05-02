<?php

namespace App\Services\Sites\WebserverConfig;

use App\Models\Site;
use App\Models\SiteWebserverConfigProfile;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\SiteNginxProvisioner;
use App\Services\Webserver\NginxConfigSyntaxTester;

class NginxWebserverConfigEngine implements WebserverConfigEngineInterface
{
    public function __construct(
        private readonly NginxSiteConfigBuilder $builder,
        private readonly SiteNginxProvisioner $provisioner,
        private readonly NginxConfigSyntaxTester $syntaxTester,
    ) {}

    public function webserver(): string
    {
        return 'nginx';
    }

    public function effectiveConfig(Site $site, ?SiteWebserverConfigProfile $profile): string
    {
        return $this->builder->build($site, $profile);
    }

    public function managedCoreHash(Site $site): string
    {
        return $this->builder->managedCoreHash($site);
    }

    public function validateLocal(string $config): array
    {
        return $this->syntaxTester->testServerBlock($config);
    }

    public function validateRemote(Site $site, string $config, ?SiteWebserverConfigProfile $profile): array
    {
        $profile ??= $site->webserverConfigProfile ?? SiteWebserverConfigProfile::query()->firstOrCreate(
            ['site_id' => $site->id],
            [
                'webserver' => 'nginx',
                'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
                'main_snippet_body' => $site->nginx_extra_raw,
            ]
        );

        return $this->provisioner->validatePendingOnServer($site, $config, $profile);
    }
}
