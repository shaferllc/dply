<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

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

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function publishDeployment(EdgeDeployment $deployment, Site $site, string $localArtifactDir): array
    {
        return $this->delivery->publishDeployment($deployment, $site, $localArtifactDir);
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function republishDeployment(EdgeDeployment $deployment, Site $site): array
    {
        return $this->delivery->republishDeployment($deployment, $site);
    }

    public function unpublish(Site $site): void
    {
        $this->delivery->unpublish($site);
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function attachDomain(Site $site, string $hostname): array
    {
        return $this->delivery->attachDomain($site, $hostname);
    }

    public function detachDomain(Site $site, string $hostname): void
    {
        $this->delivery->detachDomain($site, $hostname);
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function inspect(Site $site): array
    {
        return $this->delivery->inspect($site);
    }

    public function supportsSsr(): bool
    {
        return false;
    }
}
