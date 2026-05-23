<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Support\Edge\EdgeDeliveryContext;
use App\Support\Edge\EdgeFilesystemRegistrar;
use RuntimeException;

class EdgeDeliveryContextResolver
{
    public function forSite(Site $site): EdgeDeliveryContext
    {
        $backend = (string) ($site->edge_backend ?? '');

        if ($backend === 'org_cloudflare') {
            $credential = $this->resolveOrgCredential($site);
            $context = EdgeDeliveryContext::fromProviderCredential($credential);
            app(EdgeFilesystemRegistrar::class)->registerDisk($context);

            return $context;
        }

        return EdgeDeliveryContext::platform();
    }

    public function forProviderCredential(ProviderCredential $credential): EdgeDeliveryContext
    {
        $context = EdgeDeliveryContext::fromProviderCredential($credential);
        app(EdgeFilesystemRegistrar::class)->registerDisk($context);

        return $context;
    }

    private function resolveOrgCredential(Site $site): ProviderCredential
    {
        $credentialId = $site->edge_provider_credential_id;
        if (! is_string($credentialId) || $credentialId === '') {
            throw new RuntimeException('Edge BYO site is missing a Cloudflare credential link.');
        }

        $credential = ProviderCredential::query()->find($credentialId);
        if ($credential === null) {
            throw new RuntimeException('Linked Cloudflare credential was not found.');
        }

        if ($credential->organization_id !== null
            && $site->organization_id !== null
            && (string) $credential->organization_id !== (string) $site->organization_id) {
            throw new RuntimeException('Linked Cloudflare credential does not belong to this organization.');
        }

        if ($credential->provider !== 'cloudflare') {
            throw new RuntimeException('Edge BYO delivery requires a Cloudflare provider credential.');
        }

        return $credential;
    }
}
