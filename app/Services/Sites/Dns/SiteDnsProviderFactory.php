<?php

namespace App\Services\Sites\Dns;

use App\Models\ProviderCredential;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\DigitalOceanService;

final class SiteDnsProviderFactory
{
    public static function forCredential(ProviderCredential $credential): DnsProvider
    {
        return match ($credential->provider) {
            'digitalocean' => new DigitalOceanDnsProvider(new DigitalOceanService($credential)),
            'cloudflare' => new CloudflareDnsProvider(new CloudflareDnsService($credential)),
            default => throw new \RuntimeException(
                __('DNS automation is not available for this provider yet. Choose DigitalOcean or Cloudflare.')
            ),
        };
    }

    public static function forDigitalOceanAppConfigToken(string $token): DnsProvider
    {
        return new DigitalOceanDnsProvider(new DigitalOceanService($token));
    }
}
