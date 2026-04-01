<?php

declare(strict_types=1);

namespace App\Actions\Servers\Support;

final class ServerProviderTypeMap
{
    public static function toCredentialProvider(string $type): ?string
    {
        return match ($type) {
            'digitalocean' => 'digitalocean',
            'digitalocean_functions' => 'digitalocean',
            'hetzner' => 'hetzner',
            'linode' => 'linode',
            'vultr' => 'vultr',
            'akamai' => 'akamai',
            'scaleway' => 'scaleway',
            'upcloud' => 'upcloud',
            'equinix_metal' => 'equinix_metal',
            'fly_io' => 'fly_io',
            'aws' => 'aws',
            default => null,
        };
    }
}
