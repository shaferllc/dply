<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Organization;
use App\Services\HetznerService;
use RuntimeException;

/**
 * Platform Hetzner credentials for dply-managed servers — the VM counterpart to
 * {@see App\Support\Serverless\ServerlessPlatformContext} and
 * {@see App\Support\Edge\EdgeDeliveryContext} `platform()`.
 *
 * In managed mode dply provisions and pays for the VM on its own Hetzner project
 * (rather than the customer's connected credential), and bills it all-in
 * cost-plus. The managed create option is only offered when this is configured.
 */
final readonly class ServerHostingPlatformContext
{
    public function __construct(
        public string $apiToken,
        public string $defaultRegion,
        public string $defaultImage,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            apiToken: trim((string) config('managed_servers.hetzner.api_token', '')),
            defaultRegion: trim((string) config('managed_servers.hetzner.default_region', 'fsn1')) ?: 'fsn1',
            defaultImage: trim((string) config('managed_servers.hetzner.default_image', 'ubuntu-24.04')) ?: 'ubuntu-24.04',
        );
    }

    /**
     * Platform context for a given org. Beta orgs provision their free CX22 in a
     * SEPARATE, isolated Hetzner project (`beta_hetzner`) so one abuser can't get
     * the production-managed/Edge project suspended. Falls back to the primary
     * project when no beta token is configured (local / fake-cloud dev).
     */
    public static function forOrg(Organization $org): self
    {
        if (! $org->isBeta()) {
            return self::fromConfig();
        }

        $betaToken = trim((string) config('managed_servers.beta_hetzner.api_token', ''));
        if ($betaToken === '') {
            return self::fromConfig();
        }

        return new self(
            apiToken: $betaToken,
            defaultRegion: trim((string) config('managed_servers.beta_hetzner.default_region', 'fsn1')) ?: 'fsn1',
            defaultImage: trim((string) config('managed_servers.beta_hetzner.default_image', 'ubuntu-24.04')) ?: 'ubuntu-24.04',
        );
    }

    /**
     * True when dply's platform Hetzner project is configured and managed servers
     * can be offered/provisioned.
     */
    public function configured(): bool
    {
        return $this->apiToken !== '';
    }

    /**
     * A HetznerService bound to dply's platform token, for provisioning and
     * teardown of managed VMs.
     */
    public function hetzner(): HetznerService
    {
        if (! $this->configured()) {
            throw new RuntimeException('dply-managed servers are not configured. Set DPLY_MANAGED_HETZNER_API_TOKEN in the environment.');
        }

        return HetznerService::fromToken($this->apiToken);
    }
}
