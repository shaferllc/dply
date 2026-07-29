<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteTenantDomain;
use App\Services\Sites\Dns\DnsZoneCredentialResolver;
use App\Services\Sites\Dns\SiteDnsProviderFactory;

/**
 * Points a tenant's custom domain at its site's server by upserting an A record
 * through whichever connected DNS credential actually hosts the hostname's zone.
 * Idempotent — shared by the routing UI (on add/edit) and the scheduled reconcile
 * sweep, so the two never drift.
 */
class TenantDnsProvisioner
{
    public function __construct(
        private readonly DnsZoneCredentialResolver $resolver,
    ) {}

    /**
     * @return array{status: 'created'|'no_credential'|'no_server_ip'|'invalid'|'error', zone: ?string, message: ?string}
     */
    public function ensure(Site $site, SiteTenantDomain $tenant): array
    {
        $hostname = strtolower(trim((string) $tenant->hostname));
        if ($hostname === '') {
            return ['status' => 'invalid', 'zone' => null, 'message' => 'Tenant has no hostname.'];
        }

        $serverIp = trim((string) ($site->server?->ip_address ?? ''));
        if ($serverIp === '') {
            return ['status' => 'no_server_ip', 'zone' => null, 'message' => 'The server has no IP address yet.'];
        }

        $match = $this->resolver->resolveForHostname($site, $hostname);
        if ($match === null) {
            return ['status' => 'no_credential', 'zone' => null, 'message' => 'No connected DNS credential controls this zone.'];
        }

        $zone = $match['zone'];
        $relative = $hostname === $zone ? '@' : rtrim(substr($hostname, 0, -(strlen($zone) + 1)), '.');
        $relative = $relative === '' ? '@' : $relative;

        try {
            SiteDnsProviderFactory::forCredential($match['credential'])->upsertRecord($zone, 'A', $relative, $serverIp);

            return ['status' => 'created', 'zone' => $zone, 'message' => null];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'zone' => $zone, 'message' => $e->getMessage()];
        }
    }
}
