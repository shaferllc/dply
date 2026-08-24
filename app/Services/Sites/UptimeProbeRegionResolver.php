<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;

/**
 * Picks the uptime probe region that best matches where a site is hosted,
 * so an auto-created monitor's region label reflects the host instead of
 * defaulting to EU. Maps a provider region slug — DigitalOcean (nyc1, sfo3,
 * ams3, …) or Hetzner (fsn1, nbg1, ash, …) — onto one of the configured
 * `site_uptime.probe_regions` keys.
 */
final class UptimeProbeRegionResolver
{
    /** Provider region slug prefix → preferred probe-region key. */
    private const MAP = [
        // DigitalOcean
        'nyc' => 'us-east',
        'tor' => 'us-east',
        'sfo' => 'us-west',
        'ams' => 'eu-amsterdam',
        'lon' => 'eu-amsterdam',
        'fra' => 'eu-frankfurt',
        'sgp' => 'ap-sydney',
        'blr' => 'ap-sydney',
        'syd' => 'ap-sydney',
        // Hetzner — Falkenstein/Nuremberg/Helsinki are the eu-central zone,
        // nearest the worker-1 box in Falkenstein.
        'fsn' => 'eu-falkenstein',
        'nbg' => 'eu-falkenstein',
        'hel' => 'eu-falkenstein',
        'ash' => 'us-east',
        'hil' => 'us-west',
        'sin' => 'ap-sydney',
    ];

    public function forSite(Site $site): string
    {
        $site->loadMissing('server');

        if ($site->usesFunctionsRuntime()) {
            return $this->resolve($this->functionHostRegion($site));
        }

        return $this->resolve($site->server?->region);
    }

    /**
     * DigitalOcean Functions region: the host's `server.region`, else the
     * `faas-{region}` slug on the invocation / API host, else nyc1. Never
     * fall through to the first `probe_regions` key (Falkenstein) — that is
     * the probe worker, not the function host.
     */
    private function functionHostRegion(Site $site): string
    {
        $fromServer = strtolower(trim((string) ($site->server?->region ?? '')));
        if ($fromServer !== '') {
            return $fromServer;
        }

        foreach ($this->functionRegionCandidates($site) as $candidate) {
            $parsed = $this->regionFromFunctionsUrl($candidate);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return 'nyc1';
    }

    /** @return list<string> */
    private function functionRegionCandidates(Site $site): array
    {
        // Site::serverlessConfig() went with the serverless surface
        // (remove-cloud-edge-serverless), so there are no function URLs left to
        // parse a region out of; the server region and the nyc1 default carry
        // this now.
        $candidates = [];
        $config = [];
        foreach (['action_url', 'api_host'] as $key) {
            $value = $config[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = trim($value);
            }
        }

        $meta = is_array($site->server?->meta) ? $site->server->meta : [];
        $hostConfig = is_array($meta['digitalocean_functions'] ?? null)
            ? $meta['digitalocean_functions']
            : [];
        $apiHost = $hostConfig['api_host'] ?? null;
        if (is_string($apiHost) && trim($apiHost) !== '') {
            $candidates[] = trim($apiHost);
        }

        return $candidates;
    }

    /**
     * Parse `faas-{region}` from a DigitalOcean Functions URL or host
     * (`faas-nyc1.doserverless.co`, `faas-sfo3-abc.doserverless.co`).
     */
    private function regionFromFunctionsUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $host = preg_replace('#^https?://#i', '', $url) ?? $url;
            $host = explode('/', (string) $host, 2)[0];
        }

        if (preg_match('/^faas-([a-z]+[0-9]+)/i', $host, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Map a host region slug to a configured probe-region key, falling back
     * to the first configured region when it's unknown or unmappable.
     */
    public function resolve(?string $serverRegion): string
    {
        $regions = array_keys((array) config('site_uptime.probe_regions', []));
        $fallback = $regions[0] ?? 'us-east';

        $region = strtolower(trim((string) $serverRegion));
        if ($region === '') {
            return $fallback;
        }

        foreach (self::MAP as $prefix => $probe) {
            if (str_starts_with($region, $prefix)) {
                return in_array($probe, $regions, true) ? $probe : $fallback;
            }
        }

        return $fallback;
    }
}
