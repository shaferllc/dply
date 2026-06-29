<?php

declare(strict_types=1);

namespace App\Services\Sites\Dns;

use App\Enums\ServerProvider;
use App\Models\ProviderCredential;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Finds which of an org's connected DNS credentials actually hosts a given zone
 * (or the zone for a hostname), by probing each provider's {@see DnsProvider::controlsZone()}.
 * Used to auto-pick the right credential for the DNS tab and to auto-provision
 * tenant/custom-domain records without the operator hand-selecting a provider.
 */
class DnsZoneCredentialResolver
{
    /**
     * The credential that hosts $hostname's zone, plus the exact zone it owns —
     * probing candidate parent zones longest-first (a delegated subdomain zone
     * wins over the registrable domain). Null when nothing connected covers it.
     *
     * @return array{credential: ProviderCredential, zone: string}|null
     */
    public function resolveForHostname(Site $site, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return null;
        }

        $credentials = $this->orderedDnsCredentials($site);
        if ($credentials->isEmpty()) {
            return null;
        }

        foreach ($this->candidateZones($hostname) as $zone) {
            foreach ($credentials as $credential) {
                try {
                    if (SiteDnsProviderFactory::forCredential($credential)->controlsZone($zone)) {
                        return ['credential' => $credential, 'zone' => $zone];
                    }
                } catch (\Throwable) {
                    // Unsupported provider / unreachable credential — skip it.
                }
            }
        }

        return null;
    }

    /** Just the credential that owns an exact zone, or null. */
    public function resolveCredentialForZone(Site $site, string $zone): ?ProviderCredential
    {
        $zone = strtolower(trim($zone));
        if ($zone === '') {
            return null;
        }

        foreach ($this->orderedDnsCredentials($site) as $credential) {
            try {
                if (SiteDnsProviderFactory::forCredential($credential)->controlsZone($zone)) {
                    return $credential;
                }
            } catch (\Throwable) {
                // skip
            }
        }

        return null;
    }

    /**
     * Candidate apex zones for a hostname, longest first, stopping at two labels.
     * e.g. app.acme.co.uk → [app.acme.co.uk, acme.co.uk, co.uk].
     *
     * @return list<string>
     */
    private function candidateZones(string $hostname): array
    {
        $labels = explode('.', $hostname);
        $candidates = [];
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        return $candidates;
    }

    /**
     * Org DNS credentials, with the site's configured DNS credential probed first
     * (cheap win when it already owns the zone).
     *
     * @return Collection<int, ProviderCredential>
     */
    private function orderedDnsCredentials(Site $site): Collection
    {
        $dnsProviders = collect(ServerProvider::cases())
            ->filter(fn (ServerProvider $provider): bool => $provider->supportsDns())
            ->map(fn (ServerProvider $provider): string => $provider->value)
            ->all();

        $all = ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->whereIn('provider', $dnsProviders)
            ->orderByDesc('updated_at')
            ->get();

        $preferred = $site->dnsAutomationCredential();
        if ($preferred !== null) {
            return collect([$preferred])
                ->merge($all->reject(fn (ProviderCredential $c): bool => (string) $c->id === (string) $preferred->id))
                ->values();
        }

        return $all;
    }
}
