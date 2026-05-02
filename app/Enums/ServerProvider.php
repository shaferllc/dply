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
        };
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
