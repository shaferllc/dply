<?php

namespace App\Services\Sites\Dns;

use App\Models\ProviderCredential;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\Route53Service;

final class SiteDnsProviderFactory
{
    public static function forCredential(ProviderCredential $credential): DnsProvider
    {
        return match ($credential->provider) {
            'digitalocean' => new DigitalOceanDnsProvider(new DigitalOceanService($credential)),
            'hetzner' => new HetznerDnsProvider(new HetznerService($credential)),
            'linode', 'akamai' => new LinodeDnsProvider(new LinodeService($credential)),
            'cloudflare' => new CloudflareDnsProvider(new CloudflareDnsService($credential)),
            'aws' => new Route53DnsProvider(new Route53Service($credential)),
            default => throw new \RuntimeException(
                __('DNS automation is not available for this provider yet. Choose DigitalOcean, Hetzner, Linode, Cloudflare, or AWS (Route53).')
            ),
        };
    }

    public static function forDigitalOceanAppConfigToken(string $token): DnsProvider
    {
        return new DigitalOceanDnsProvider(new DigitalOceanService($token));
    }
}
