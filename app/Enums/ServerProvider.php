<?php

namespace App\Enums;

enum ServerProvider: string
{
    case DigitalOcean = 'digitalocean';
    case Hetzner = 'hetzner';
    case Linode = 'linode';
    case Vultr = 'vultr';
    case UpCloud = 'upcloud';
    case Scaleway = 'scaleway';
    case Ovh = 'ovh';
    case Rackspace = 'rackspace';
    case EquinixMetal = 'equinix_metal';
    case Akamai = 'akamai';
    case FlyIo = 'fly_io';
    case Render = 'render';
    case Railway = 'railway';
    case Coolify = 'coolify';
    case CapRover = 'cap_rover';
    case Aws = 'aws';
    case Cloudflare = 'cloudflare';
    case Gcp = 'gcp';
    case Azure = 'azure';
    case Oracle = 'oracle';
    case Custom = 'custom';
    case Gandi = 'gandi';
    case Namecheap = 'namecheap';
    case VercelDns = 'vercel_dns';
    case Ploi = 'ploi';

    /**
     * Human-readable label for UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::DigitalOcean => 'DigitalOcean',
            self::Hetzner => 'Hetzner',
            self::Linode => 'Linode',
            self::Vultr => 'Vultr',
            self::UpCloud => 'UpCloud',
            self::Scaleway => 'Scaleway',
            self::Ovh => 'OVH',
            self::Rackspace => 'Rackspace',
            self::EquinixMetal => 'Equinix Metal',
            self::Akamai => 'Akamai',
            self::FlyIo => 'Fly.io',
            self::Render => 'Render',
            self::Railway => 'Railway',
            self::Coolify => 'Coolify',
            self::CapRover => 'CapRover',
            self::Aws => 'AWS',
            self::Cloudflare => 'Cloudflare',
            self::Gcp => 'GCP',
            self::Azure => 'Azure',
            self::Oracle => 'Oracle Cloud',
            self::Custom => 'Custom',
            self::Gandi => 'Gandi',
            self::Namecheap => 'Namecheap',
            self::VercelDns => 'Vercel DNS',
            self::Ploi => 'Ploi',
        };
    }

    /**
     * Whether this provider can be used for compute / server provisioning. Mirrors the
     * `hasFullSupport()` semantics for the credential UI surface — exposed separately so
     * the credentials page can filter by capability without overloading "full support."
     */
    public function supportsCompute(): bool
    {
        return $this->hasFullSupport();
    }

    /**
     * Whether this provider can be used for DNS automation (site DNS settings, DNS-01,
     * preview-hostname provisioning). DigitalOcean and AWS are dual-purpose; Cloudflare
     * and the stub providers are DNS-only.
     */
    public function supportsDns(): bool
    {
        return match ($this) {
            self::DigitalOcean,
            self::Cloudflare,
            self::Aws,
            self::Gandi,
            self::Namecheap,
            self::VercelDns => true,
            default => false,
        };
    }

    /**
     * Whether this provider is a source for inventory imports (existing fleets that
     * dply can read sites/servers from and migrate). Distinct from compute/DNS —
     * import providers don't host anything; dply only talks to their APIs to read
     * the user's existing state and orchestrate a one-way move to dply-managed servers.
     */
    public function supportsImport(): bool
    {
        return match ($this) {
            self::Ploi => true,
            default => false,
        };
    }

    /**
     * Capability tags for badge rendering on credential rows.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        $caps = [];
        if ($this->supportsCompute()) {
            $caps[] = 'compute';
        }
        if ($this->supportsDns()) {
            $caps[] = 'dns';
        }
        if ($this->supportsImport()) {
            $caps[] = 'import';
        }

        return $caps;
    }

    /**
     * Provider keys that can manage DNS for sites. Canonical taxonomy lives here so the
     * UI, the DNS provider factory, and the credential model all read from one place.
     *
     * @return list<string>
     */
    public static function dnsProviderKeys(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->supportsDns())
        ));
    }

    /**
     * Provider keys that can be used for compute / server provisioning.
     *
     * @return list<string>
     */
    public static function computeProviderKeys(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->supportsCompute())
        ));
    }

    /**
     * Provider keys that can be used as inventory-import sources.
     *
     * @return list<string>
     */
    public static function importProviderKeys(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p->supportsImport())
        ));
    }

    /**
     * Whether this provider has full support: service class, provision/poll jobs,
     * create tab, and destroy handling. Otherwise only credentials are stored.
     */
    public function hasFullSupport(): bool
    {
        return match ($this) {
            self::DigitalOcean,
            self::Hetzner,
            self::Linode,
            self::Vultr,
            self::UpCloud,
            self::Scaleway,
            self::EquinixMetal,
            self::Akamai,
            self::FlyIo,
            self::Aws => true,
            self::Cloudflare => false,
            default => false,
        };
    }

    /**
     * Providers that accept credentials only (no create/destroy yet).
     */
    public static function credentialOnly(): array
    {
        return array_filter(
            self::cases(),
            fn (self $p) => ! $p->hasFullSupport() && $p !== self::Custom
        );
    }

    /**
     * All provider values (for validation, etc.).
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Provider values allowed for provider_credentials (excludes Custom).
     */
    public static function valuesForCredentials(): array
    {
        return array_values(array_map(
            fn (self $p) => $p->value,
            array_filter(self::cases(), fn (self $p) => $p !== self::Custom)
        ));
    }
}
