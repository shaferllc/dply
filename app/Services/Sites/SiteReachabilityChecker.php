<?php

namespace App\Services\Sites;

use App\Models\Site;
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
        $domains = $site->domains instanceof \Illuminate\Support\Collection
            ? $site->domains
            : $site->domains()->get();
        $primaryHostname = $domains->firstWhere('is_primary', true)?->hostname
            ?? $domains->first()?->hostname;

        $hostnames = collect([
            $site->testingHostname(),
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
                    ->withHeaders(['Host' => $hostname])
                    ->get($url);

                if ($response->successful() || in_array($response->status(), [301, 302, 307, 308], true)) {
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
}
