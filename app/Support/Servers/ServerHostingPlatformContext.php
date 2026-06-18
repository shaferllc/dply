<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Services\HetznerService;
use App\Services\VultrService;
use RuntimeException;

/**
 * Platform cloud credentials for dply-managed servers — the VM counterpart to
 * {@see App\Modules\Serverless\Support\ServerlessPlatformContext} and
 * {@see App\Support\Edge\EdgeDeliveryContext} `platform()`.
 *
 * In managed mode dply provisions and pays for the VM on its own platform cloud
 * account (rather than the customer's connected credential), and bills it all-in
 * cost-plus. The managed backend is a SINGLE operator-configured provider
 * (`managed_servers.provider` — hetzner or vultr); this context resolves the
 * active provider's token, defaults, catalog, and service. The managed create
 * option is only offered when {@see configured()} is true.
 */
final readonly class ServerHostingPlatformContext
{
    public function __construct(
        public ServerProvider $provider,
        public string $apiToken,
        public string $defaultRegion,
        public string $defaultImage,
    ) {}

    public static function fromConfig(): self
    {
        return self::forProvider(self::resolveProvider());
    }

    /**
     * The operator-configured managed backend provider, defaulting to Hetzner.
     */
    private static function resolveProvider(): ServerProvider
    {
        $key = strtolower(trim((string) config('managed_servers.provider', 'hetzner'))) ?: 'hetzner';

        return match ($key) {
            'vultr' => ServerProvider::Vultr,
            default => ServerProvider::Hetzner,
        };
    }

    private static function forProvider(ServerProvider $provider): self
    {
        $key = $provider->value;
        $fallback = $provider === ServerProvider::Vultr
            ? ['region' => 'ewr', 'image' => '2152']
            : ['region' => 'fsn1', 'image' => 'ubuntu-24.04'];

        return new self(
            provider: $provider,
            apiToken: trim((string) config("managed_servers.{$key}.api_token", '')),
            defaultRegion: trim((string) config("managed_servers.{$key}.default_region", $fallback['region'])) ?: $fallback['region'],
            defaultImage: trim((string) config("managed_servers.{$key}.default_image", $fallback['image'])) ?: $fallback['image'],
        );
    }

    /**
     * Platform context for a given org. Beta orgs provision their free CX22 in a
     * SEPARATE, isolated Hetzner project (`beta_hetzner`) so one abuser can't get
     * the production-managed/Edge project suspended. Beta isolation is
     * Hetzner-specific — when the active backend is Vultr (or no beta token is
     * configured) we fall through to the primary backend.
     */
    public static function forOrg(Organization $org): self
    {
        $base = self::fromConfig();

        if ($base->provider !== ServerProvider::Hetzner || ! $org->isBeta()) {
            return $base;
        }

        $betaToken = trim((string) config('managed_servers.beta_hetzner.api_token', ''));
        if ($betaToken === '') {
            return $base;
        }

        return new self(
            provider: ServerProvider::Hetzner,
            apiToken: $betaToken,
            defaultRegion: trim((string) config('managed_servers.beta_hetzner.default_region', 'fsn1')) ?: 'fsn1',
            defaultImage: trim((string) config('managed_servers.beta_hetzner.default_image', 'ubuntu-24.04')) ?: 'ubuntu-24.04',
        );
    }

    /**
     * True when the active managed backend is configured and managed servers can
     * be offered/provisioned.
     */
    public function configured(): bool
    {
        return $this->apiToken !== '';
    }

    /**
     * Curated region map for the active backend (slug => display label).
     *
     * @return array<string, string>
     */
    public function regions(): array
    {
        return (array) config("managed_servers.catalogs.{$this->provider->value}.regions", []);
    }

    /**
     * Curated size list for the active backend.
     *
     * @return list<array<string, mixed>>
     */
    public function sizes(): array
    {
        return array_values((array) config("managed_servers.catalogs.{$this->provider->value}.sizes", []));
    }

    private function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('dply-managed servers are not configured. Set the platform API token for the '.$this->provider->label().' backend in the environment.');
        }
    }

    /**
     * A HetznerService bound to dply's platform token, for provisioning and
     * teardown of managed Hetzner VMs. Only valid when the active backend is
     * Hetzner.
     */
    public function hetzner(): HetznerService
    {
        $this->assertConfigured();

        return HetznerService::fromToken($this->apiToken);
    }

    /**
     * A VultrService bound to dply's platform token, for provisioning and teardown
     * of managed Vultr VMs. Only valid when the active backend is Vultr.
     */
    public function vultr(): VultrService
    {
        $this->assertConfigured();

        return VultrService::fromToken($this->apiToken);
    }
}
