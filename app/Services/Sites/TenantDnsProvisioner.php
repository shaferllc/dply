<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SiteTenantDomain;
use App\Services\Sites\Dns\DnsZoneCredentialResolver;
use App\Services\Sites\Dns\SiteDnsProviderFactory;

/**
 * Points a customer hostname at its site's server by upserting an A record
 * through whichever connected DNS credential actually hosts the hostname's zone.
 * Idempotent — shared by the routing UI (on add/edit), the scheduled reconcile
 * sweep, and the Domains tab's auto-attach, so they never drift.
 *
 * Named for tenants because that is what it shipped for; {@see ensureHostname()}
 * is the general entry point and takes any hostname.
 */
class TenantDnsProvisioner
{
    public function __construct(
        private readonly DnsZoneCredentialResolver $resolver,
    ) {}

    /**
     * @return array{status: 'created'|'no_credential'|'no_server_ip'|'invalid'|'error', zone: ?string, message: ?string, credential_id: ?string, provider: ?string}
     */
    public function ensure(Site $site, SiteTenantDomain $tenant): array
    {
        return $this->ensureHostname($site, (string) $tenant->hostname);
    }

    /**
     * @return array{status: 'created'|'no_credential'|'no_server_ip'|'invalid'|'error', zone: ?string, message: ?string, credential_id: ?string, provider: ?string}
     */
    public function ensureHostname(Site $site, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return self::result('invalid', message: 'No hostname to point.');
        }

        $serverIp = trim((string) ($site->server->ip_address ?? ''));
        if ($serverIp === '') {
            return self::result('no_server_ip', message: 'The server has no IP address yet.');
        }

        $match = $this->resolver->resolveForHostname($site, $hostname);
        if ($match === null) {
            return self::result('no_credential', message: 'No connected DNS credential controls this zone.');
        }

        $zone = $match['zone'];
        $relative = $hostname === $zone ? '@' : rtrim(substr($hostname, 0, -(strlen($zone) + 1)), '.');
        $relative = $relative === '' ? '@' : $relative;

        try {
            SiteDnsProviderFactory::forCredential($match['credential'])->upsertRecord($zone, 'A', $relative, $serverIp);

            return self::result('created', zone: $zone, credential: $match['credential']);
        } catch (\Throwable $e) {
            return self::result('error', zone: $zone, message: $e->getMessage(), credential: $match['credential']);
        }
    }

    /**
     * Callers backfill the site's saved zone/credential from a successful
     * attach, so the credential that actually owns the zone wins over
     * dnsAutomationCredential()'s "most recently updated" guess.
     *
     * @return array{status: string, zone: ?string, message: ?string, credential_id: ?string, provider: ?string}
     */
    private static function result(string $status, ?string $zone = null, ?string $message = null, ?ProviderCredential $credential = null): array
    {
        return [
            'status' => $status,
            'zone' => $zone,
            'message' => $message,
            'credential_id' => $credential !== null ? (string) $credential->id : null,
            'provider' => $credential?->dnsProviderLabel(),
        ];
    }
}
