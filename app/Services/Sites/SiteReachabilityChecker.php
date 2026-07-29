<?php

namespace App\Services\Sites;

use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SiteReachabilityChecker
{
    /**
     * Cloudflare's published proxy IPv4 ranges. When a hostname resolves to one
     * of these it is orange-clouded — the A record points at Cloudflare's edge,
     * NOT a misconfigured server — so the "wrong server" alarm is a false alarm.
     * Keep in sync with https://www.cloudflare.com/ips/.
     *
     * @var list<string>
     */
    private const CLOUDFLARE_IPV4_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * @return array{
     *   ok: bool,
     *   hostname: ?string,
     *   url: ?string,
     *   error: ?string,
     *   checked_at: string,
     *   checks: list<array{hostname: string, url: string, ok: bool, error: ?string}>
     * }
     */
    /** @return array<string, mixed> */
    public function check(Site $site): array
    {
        $previewDomains = $site->previewDomains instanceof Collection
            ? $site->previewDomains
            : $site->previewDomains()->get();
        $domains = $site->domains instanceof Collection
            ? $site->domains
            : $site->domains()->get();
        $primaryHostname = $domains->firstWhere('is_primary', true)->hostname
            ?? $domains->first()?->hostname;
        $primaryPreviewHostname = $previewDomains->firstWhere('is_primary', true)->hostname
            ?? $previewDomains->first()?->hostname;

        $hostnames = collect([
            $primaryPreviewHostname ?: $site->testingHostname(),
            $primaryHostname,
        ])->filter(fn (mixed $hostname): bool => is_string($hostname) && $hostname !== '')
            ->unique()
            ->values();

        $checkedAt = now()->toIso8601String();
        $lastFailure = null;
        $checks = [];

        foreach ($hostnames as $hostname) {
            $resolved = gethostbyname($hostname);
            if ($resolved === $hostname) {
                $checks[] = [
                    'hostname' => $hostname,
                    'url' => 'http://'.$hostname,
                    'ok' => false,
                    'error' => 'DNS does not resolve yet.',
                ];

                continue;
            }

            $url = 'http://'.$hostname;

            try {
                $response = Http::timeout(5)
                    ->withoutRedirecting()
                    ->withHeaders(['Host' => $hostname])
                    ->get($url);

                if ($this->statusMeansReachable($response->status())) {
                    $checks[] = [
                        'hostname' => $hostname,
                        'url' => $url,
                        'ok' => true,
                        'error' => null,
                    ];

                    return [
                        'ok' => true,
                        'hostname' => $hostname,
                        'url' => $url,
                        'error' => null,
                        'checked_at' => $checkedAt,
                        'checks' => $checks,
                    ];
                }

                $checks[] = [
                    'hostname' => $hostname,
                    'url' => $url,
                    'ok' => false,
                    'error' => 'Unexpected HTTP status '.$response->status().'.',
                ];
                $lastFailure = [
                    'ok' => false,
                    'hostname' => $hostname,
                    'url' => $url,
                    'error' => 'Unexpected HTTP status '.$response->status().'.',
                    'checked_at' => $checkedAt,
                    'checks' => $checks,
                ];
            } catch (\Throwable $e) {
                $checks[] = [
                    'hostname' => $hostname,
                    'url' => $url,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
                $lastFailure = [
                    'ok' => false,
                    'hostname' => $hostname,
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'checked_at' => $checkedAt,
                    'checks' => $checks,
                ];
            }
        }

        if ($lastFailure !== null) {
            return $lastFailure;
        }

        return [
            'ok' => false,
            'hostname' => null,
            'url' => null,
            'error' => 'No site hostname resolves yet.',
            'checked_at' => $checkedAt,
            'checks' => $checks,
        ];
    }

    /**
     * Reachability for a SPECIFIC hostname (vs. the site's primary hostnames) —
     * used to gate "Add SSL" so we don't queue an HTTP-01 challenge for a domain
     * that doesn't point here yet (it would just fail at the CA).
     *
     * @return array{
     *   ok: bool,
     *   resolves: bool,
     *   points_here: bool,
     *   behind_cloudflare: bool,
     *   http_ok: bool,
     *   resolved_ips: list<string>,
     *   server_ip: string,
     *   error: ?string,
     *   checked_at: string
     * }
     */
    /** @return array<string, mixed> */
    public function checkHostname(Site $site, string $hostname): array
    {
        $hostname = strtolower(trim($hostname));
        $serverIp = trim((string) ($site->server->ip_address ?? ''));
        $resolved = @gethostbynamel($hostname) ?: [];
        $resolves = $resolved !== [];
        $pointsHere = $serverIp !== '' && in_array($serverIp, $resolved, true);

        // Orange-clouded domains resolve to Cloudflare's anycast IPs, not this
        // box — so a naive IP compare reports "wrong server" when nothing is
        // actually misconfigured. Detect the proxy so we can say so plainly
        // instead of telling the operator to "fix" a correct A record.
        $behindCloudflare = ! $pointsHere && $this->ipsBehindCloudflare($resolved);

        // Probe HTTP when DNS points straight here, OR when it's proxied through
        // Cloudflare (the GET then traverses the proxy to this origin — exactly
        // the path an HTTP-01 challenge takes). Otherwise the GET would hit
        // whatever third party the domain resolves to (and could hang), which
        // tells us nothing about this server.
        $httpOk = false;
        $httpError = null;
        if ($pointsHere || $behindCloudflare) {
            try {
                $response = Http::timeout(5)
                    ->withoutRedirecting()
                    ->withHeaders(['Host' => $hostname])
                    ->get('http://'.$hostname);
                $httpOk = $this->statusMeansReachable($response->status());
                if (! $httpOk) {
                    $httpError = 'HTTP '.$response->status();
                }
            } catch (\Throwable $e) {
                $httpError = $e->getMessage();
            }
        }

        $error = null;
        if (! $resolves) {
            $error = __('“:host” does not resolve in DNS yet. Point an A record at :ip, then re-check.', [
                'host' => $hostname,
                'ip' => $serverIp !== '' ? $serverIp : __('this server'),
            ]);
        } elseif ($behindCloudflare) {
            // Not an error in itself — Cloudflare serves HTTPS at its edge, so an
            // origin cert is optional. Only flag it if the proxied origin isn't
            // answering at all (then even an origin HTTP-01 challenge would fail).
            if (! $httpOk) {
                $error = __('“:host” is served through Cloudflare, but this origin isn’t answering the proxied HTTP request yet (:err). Cloudflare can still serve HTTPS at its edge regardless.', [
                    'host' => $hostname,
                    'err' => $httpError ?? __('no response'),
                ]);
            }
        } elseif (! $pointsHere) {
            $error = __('“:host” resolves to :got, not this server (:ip). Update its A record before requesting SSL.', [
                'host' => $hostname,
                'got' => implode(', ', $resolved),
                'ip' => $serverIp !== '' ? $serverIp : __('unknown'),
            ]);
        } elseif (! $httpOk) {
            $error = __('“:host” points here but isn’t answering over HTTP yet (:err). The site must serve on port 80 for HTTP validation.', [
                'host' => $hostname,
                'err' => $httpError ?? __('no response'),
            ]);
        }

        return [
            'ok' => $pointsHere && $httpOk,
            'resolves' => $resolves,
            'points_here' => $pointsHere,
            'behind_cloudflare' => $behindCloudflare,
            'http_ok' => $httpOk,
            'resolved_ips' => array_values($resolved),
            'server_ip' => $serverIp,
            'error' => $error,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Whether EVERY resolved IP belongs to a Cloudflare proxy range — i.e. the
     * domain is orange-clouded. A mix of Cloudflare and non-Cloudflare IPs is
     * treated as NOT behind Cloudflare, so a genuinely wrong A record alongside
     * a stale Cloudflare one still trips the normal warning.
     *
     * @param  list<string>  $ips
     */
    private function ipsBehindCloudflare(array $ips): bool
    {
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! $this->ipInCloudflareRange($ip)) {
                return false;
            }
        }

        return true;
    }

    private function ipInCloudflareRange(string $ip): bool
    {
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false; // IPv6 / malformed — not matched against these IPv4 ranges.
        }

        foreach (self::CLOUDFLARE_IPV4_RANGES as $range) {
            [$subnet, $bits] = explode('/', $range);
            $subnetLong = ip2long($subnet);
            if ($subnetLong === false) {
                continue;
            }

            $mask = -1 << (32 - (int) $bits);
            if (($ipLong & $mask) === ($subnetLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an HTTP status means the webserver is actively answering on this
     * hostname — i.e. the site is reachable, even if the response itself isn't
     * a 2xx. Crucially this includes 401/403 so a site gated by basic auth or
     * a deny rule still flips the provisioner from "waiting_for_http" to
     * "ready" — the htaccess/htpasswd file IS the site's intended behavior.
     *
     * Also accepts a handful of other 4xx codes (404, 405, 410) where the
     * server is plainly responding but disagrees with this exact GET. That's
     * still a reachable site — any further misconfig is a separate concern
     * from "did provisioning finish writing the webserver config."
     *
     * 5xx is intentionally excluded: that means the server is up but its app
     * stack is broken, which we shouldn't celebrate as ready.
     */
    private function statusMeansReachable(int $status): bool
    {
        if ($status >= 200 && $status < 400) {
            return true;
        }

        // A redirect to HTTPS still proves something is listening on :80 — do
        // not follow the redirect (port 443 may not be configured yet).
        if (in_array($status, [301, 302, 307, 308], true)) {
            return true;
        }

        return in_array($status, [401, 403, 404, 405, 410], true);
    }
}
