<?php

namespace App\Services\Sites;

use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SiteReachabilityChecker
{
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
    public function check(Site $site): array
    {
        $previewDomains = $site->previewDomains instanceof Collection
            ? $site->previewDomains
            : $site->previewDomains()->get();
        $domains = $site->domains instanceof Collection
            ? $site->domains
            : $site->domains()->get();
        $primaryHostname = $domains->firstWhere('is_primary', true)?->hostname
            ?? $domains->first()?->hostname;
        $primaryPreviewHostname = $previewDomains->firstWhere('is_primary', true)?->hostname
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
