<?php

namespace App\Services\Sites\Dns;

use App\Models\ProviderCredential;
use App\Services\AzureDnsService;
use App\Services\Cloudflare\CloudflareDnsService;
use App\Services\DigitalOceanService;
use App\Services\GcpDnsService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\Route53Service;
use App\Services\VultrService;

final class SiteDnsProviderFactory
{
    public static function forCredential(ProviderCredential $credential): DnsProvider
    {
        return match ($credential->provider) {
            'digitalocean' => new DigitalOceanDnsProvider(new DigitalOceanService($credential)),
            'hetzner' => new HetznerDnsProvider(new HetznerService($credential)),
            'linode' => new LinodeDnsProvider(new LinodeService($credential)),
            'vultr' => new VultrDnsProvider(new VultrService($credential)),
            'azure' => new AzureDnsProvider(new AzureDnsService($credential)),
            'cloudflare' => new CloudflareDnsProvider(new CloudflareDnsService($credential)),
            'aws' => new Route53DnsProvider(new Route53Service($credential)),
            'gcp' => new GcpDnsProvider(new GcpDnsService($credential)),
            default => throw new \RuntimeException(
                __('DNS automation is not available for this provider yet. Choose DigitalOcean, Hetzner, Linode, Vultr, Azure, Cloudflare, AWS (Route53), or Google Cloud DNS.')
            ),
        };
    }

    public static function forDigitalOceanAppConfigToken(string $token): DnsProvider
    {
        return new DigitalOceanDnsProvider(new DigitalOceanService($token));
    }
}
