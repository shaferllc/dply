<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;

class OrgCloudflareEdgeBackend implements EdgeBackend
{
    public function __construct(
        private readonly CloudflareEdgeDelivery $delivery,
    ) {}

    public function providerKey(): string
    {
        return 'org_cloudflare';
    }

    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array
    {
        return $this->delivery->publishDeployment($deployment, $site, $localArtifactDir);
    }

    public function unpublish(Site $site): void
    {
        $this->delivery->unpublish($site);
    }

    public function attachDomain(Site $site, string $hostname): array
    {
        return $this->delivery->attachDomain($site, $hostname);
    }

    public function detachDomain(Site $site, string $hostname): void
    {
        $this->delivery->detachDomain($site, $hostname);
    }

    public function inspect(Site $site): array
    {
        return $this->delivery->inspect($site);
    }

    public function supportsSsr(): bool
    {
        return false;
    }
}
