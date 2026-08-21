<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeCloudflareClient;
use App\Modules\Serverless\Support\ServerlessAssetHost;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads asset delivery traffic per site from Cloudflare's per-hostname
 * analytics.
 *
 * Each site's assets are served from their own hostname, which is what makes
 * this possible at all: `httpRequestsAdaptiveGroups` groups by
 * `clientRequestHTTPHost`, so egress falls out per site with no log parsing.
 * The client is borrowed from the Edge module rather than reimplemented —
 * same Cloudflare account, same GraphQL query, same token.
 *
 * ## Why billable hostnames are a subset
 *
 * A custom asset domain is a Cloudflare-for-SaaS hostname whose origin is the
 * site's OWN default asset hostname (that is what lets one fleet-wide rule
 * route every site — see {@see ServerlessAssetHost}). The origin fetch
 * re-enters the zone, so the same bytes are logged twice: once against
 * `cdn.acme.com` and once against `acme-a1b2c3d4-assets.{apex}`. Billing the
 * sum would charge the customer double.
 *
 * The site's ASSET_URL only ever points at one hostname, so exactly one of the
 * two is customer-facing and the other is the internal hop:
 *
 *   custom hostnames attached  ->  bill the custom ones
 *   otherwise                  ->  bill the default
 *
 * Residual undercount: stale HTML cached before a custom domain was attached
 * still requests the default hostname, and that traffic goes unbilled. It is
 * small, it decays, and it errs in the customer's favour.
 *
 * Raw per-hostname numbers are recorded alongside the billed total, so the
 * split is auditable and a change to this rule can be recomputed from stored
 * data instead of re-collected.
 */
class ServerlessAssetEgressReader
{
    public function __construct(private ?EdgeCloudflareClient $cloudflare = null) {}

    public function isAvailable(): bool
    {
        return $this->client()->canCollectAnalytics();
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, array{
     *     requests: int,
     *     bytes: int,
     *     by_hostname: array<string, array{requests: int, bytes: int}>,
     * }>  keyed by site id
     */
    public function usageForSites(
        Collection $sites,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): array {
        if ($sites->isEmpty() || ! $this->isAvailable()) {
            return [];
        }

        $usageByHostname = $this->fetchByZone($sites, $periodStart, $periodEnd);
        if ($usageByHostname === []) {
            return [];
        }

        $results = [];

        foreach ($sites as $site) {
            $byHostname = [];
            foreach (ServerlessAssetHost::hostnames($site) as $hostname) {
                $totals = $usageByHostname[$hostname] ?? null;
                if ($totals === null) {
                    continue;
                }

                $byHostname[$hostname] = $totals;
            }

            if ($byHostname === []) {
                continue;
            }

            $requests = 0;
            $bytes = 0;
            foreach ($this->billableHostnames($site) as $hostname) {
                $requests += $byHostname[$hostname]['requests'] ?? 0;
                $bytes += $byHostname[$hostname]['bytes'] ?? 0;
            }

            $results[(string) $site->id] = [
                'requests' => $requests,
                'bytes' => $bytes,
                'by_hostname' => $byHostname,
            ];
        }

        return $results;
    }

    /**
     * The hostnames whose traffic this site is charged for. See the class
     * docblock for why this is not simply "all of them".
     *
     * @return list<string>
     */
    public function billableHostnames(Site $site): array
    {
        $custom = ServerlessAssetHost::customHostnames($site);
        if ($custom !== []) {
            return $custom;
        }

        $default = ServerlessAssetHost::hostname($site);

        return $default === null ? [] : [$default];
    }

    /**
     * Fetch every site's hostnames, one GraphQL call per zone.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<string, array{requests: int, bytes: int}>
     */
    private function fetchByZone(
        Collection $sites,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): array {
        /** @var array<string, list<string>> $hostnamesByZone */
        $hostnamesByZone = [];

        foreach ($sites as $site) {
            $hostnames = ServerlessAssetHost::hostnames($site);
            if ($hostnames === []) {
                continue;
            }

            // Custom hostnames are Cloudflare-for-SaaS records owned by the
            // same zone as the site's apex, so one bucket per apex covers both.
            $zone = ServerlessTestingDomains::apexFor($site->getKey());
            $hostnamesByZone[$zone] = array_merge($hostnamesByZone[$zone] ?? [], $hostnames);
        }

        $usage = [];

        foreach ($hostnamesByZone as $zone => $hostnames) {
            $hostnames = array_values(array_unique($hostnames));

            try {
                $zoneUsage = $this->client()->fetchHttpUsageByHostnames(
                    $hostnames,
                    $periodStart,
                    $periodEnd,
                    $zone,
                );
            } catch (Throwable $e) {
                Log::warning('serverless.assets.cloudflare_fetch_failed', [
                    'zone' => $zone,
                    'hostnames' => count($hostnames),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($zoneUsage as $hostname => $totals) {
                $host = strtolower((string) $hostname);
                $usage[$host] = [
                    'requests' => ($usage[$host]['requests'] ?? 0) + $totals->requests,
                    'bytes' => ($usage[$host]['bytes'] ?? 0) + $totals->bytesEgress,
                ];
            }
        }

        return $usage;
    }

    private function client(): EdgeCloudflareClient
    {
        return $this->cloudflare ?? EdgeCloudflareClient::fromConfig();
    }
}
