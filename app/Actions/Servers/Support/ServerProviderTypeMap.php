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
            'digitalocean_kubernetes' => 'digitalocean',
            'hetzner' => 'hetzner',
            'linode' => 'linode',
            'vultr' => 'vultr',
            'upcloud' => 'upcloud',
            'aws' => 'aws',
            'azure' => 'azure',
            'oracle' => 'oracle',
            'aws_lambda' => 'aws',
            'aws_kubernetes' => 'aws',
            default => null,
        };
    }
}
