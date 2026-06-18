<?php

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Probe a site's primary customer domain to detect whether Cloudflare is
 * terminating TLS at its edge (the domain is "orange-clouded"). When it is,
 * dply doesn't need to issue or renew an origin certificate, so the
 * Certificates surface can say so instead of nagging for a cert request.
 *
 * Detection is header-based and works even when the Cloudflare account isn't
 * one dply holds a DNS token for: any response served through Cloudflare's
 * proxy carries a `cf-ray` header and `server: cloudflare`. We never run SSH —
 * this is a plain outbound HTTP request from the control plane.
 */
class DetectSiteCloudflareTlsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 20;

    public function __construct(
        public int $siteId
    ) {}

    public function handle(): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $domain = $site->primaryDomain();
        $host = $domain?->hostname;
        $host = is_string($host) ? strtolower(trim($host)) : '';

        // Only real customer domains can sit behind Cloudflare. The dply testing
        // hostname (*.on-dply.com) never does, so don't waste a probe on it.
        $testingZone = $site->testingZone();
        $isTestingHost = $testingZone !== null && str_ends_with($host, '.'.$testingZone);

        if ($host === '' || $isTestingHost) {
            $site->setCloudflareTlsResult(false, $host, null, null);

            return;
        }

        $server = null;
        $cfRay = null;
        $terminating = false;

        try {
            $response = Http::timeout(8)->connectTimeout(4)
                ->withHeaders(['User-Agent' => 'dply-cloudflare-probe/1.0'])
                ->get('https://'.$host);

            $server = $response->header('Server') ?: null;
            $cfRay = $response->header('CF-Ray') ?: null;

            // A `cf-ray` header is emitted by every Cloudflare-proxied response;
            // `server: cloudflare` is the belt-and-suspenders signal.
            $terminating = $cfRay !== null
                || ($server !== null && str_contains(strtolower($server), 'cloudflare'));
        } catch (\Throwable) {
            // Unreachable / TLS handshake failure — treat as "not detected" and
            // leave the normal certificate UI in place.
            $terminating = false;
        }

        $site->setCloudflareTlsResult($terminating, $host, $server, $cfRay);
    }
}
