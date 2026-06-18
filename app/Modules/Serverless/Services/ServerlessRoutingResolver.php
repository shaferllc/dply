<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Modules\Serverless\Http\Middleware\ResolveServerlessCustomDomain;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Read-side helper for the serverless edge proxy.
 *
 * Returns the routing rules for a site in one shot: redirects (ordered),
 * static response headers, CORS policy, and verified custom-domain
 * hostnames. The proxy controller calls this on every request, so the
 * read is cached per-site for {@see self::CACHE_TTL_SECONDS}. The Livewire
 * routing page calls {@see invalidate()} whenever it mutates state.
 *
 * Data sources (all meta-backed for v1 — `site_redirects` has no `meta`
 * column, and SiteDomain is overkill since serverless never touches the
 * host webserver):
 *   - Redirects: `site.meta.serverless.routing.redirects[*]`.
 *   - Headers / CORS: plain bags on `site.meta.serverless.routing.*`.
 *   - Custom domains: `site.meta.serverless.routing.custom_domains[*]`.
 */
final class ServerlessRoutingResolver
{
    private const CACHE_TTL_SECONDS = 30;

    /**
     * @return array{
     *     redirects: list<array{from: string, to: string, status: int, kind: string}>,
     *     headers: list<array{name: string, value: string}>,
     *     cors: array{
     *         enabled: bool,
     *         origins: list<string>,
     *         methods: list<string>,
     *         headers: list<string>,
     *         allow_credentials: bool,
     *         max_age: int,
     *     },
     *     custom_domains: list<array{hostname: string, mode: string, dns_status: string, cname_target: string, verified_at: ?string, error: ?string}>,
     * }
     */
    /** @return array<string, mixed> */
    public function forSite(Site $site): array
    {
        return Cache::remember(
            $this->cacheKey($site),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->build($site),
        );
    }

    /**
     * Drop the cached entry. Callers (Livewire mutations, custom-domain
     * provisioner) invoke this after any state change so the next proxy
     * request reads the fresh state instead of waiting up to 30s.
     */
    public function invalidate(Site $site): void
    {
        Cache::forget($this->cacheKey($site));
        // Also drop the global custom-domain host→site map so newly-added
        // (or removed) custom domains route correctly on the next request.
        ResolveServerlessCustomDomain::invalidateHostMap();
    }

    /**
     * @return array{
     *     redirects: list<array{from: string, to: string, status: int, kind: string}>,
     *     headers: list<array{name: string, value: string}>,
     *     cors: array{enabled: bool, origins: list<string>, methods: list<string>, headers: list<string>, allow_credentials: bool, max_age: int},
     *     custom_domains: list<array{hostname: string, mode: string, dns_status: string, cname_target: string, verified_at: ?string, error: ?string}>,
     * }
     */
    private function build(Site $site): array
    {
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
        $routing = is_array($serverless['routing'] ?? null) ? $serverless['routing'] : [];

        return [
            'redirects' => $this->normalizeRedirects($routing['redirects'] ?? []),
            'headers' => $this->normalizeHeaders($routing['headers'] ?? []),
            'cors' => $this->normalizeCors($routing['cors'] ?? []),
            'custom_domains' => $this->normalizeCustomDomains($routing['custom_domains'] ?? []),
        ];
    }

    /**
     * Normalize redirect bag from meta JSON. First entry wins so the
     * Livewire page persists them in display/sort order.
     *
     * @param  mixed  $raw
     * @return list<array{from: string, to: string, status: int, kind: string}>
     */
    private function normalizeRedirects($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $from = trim((string) ($entry['from'] ?? ''));
            $to = trim((string) ($entry['to'] ?? ''));
            if ($from === '' || $to === '') {
                continue;
            }
            $status = (int) ($entry['status'] ?? 302);
            if (! in_array($status, [301, 302, 307, 308], true)) {
                $status = 302;
            }
            $out[] = [
                'from' => $from,
                'to' => $to,
                'status' => $status,
                'kind' => (string) ($entry['kind'] ?? 'exact'),
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return list<array{name: string, value: string}>
     */
    private function normalizeHeaders($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            $value = (string) ($entry['value'] ?? '');
            if ($name === '') {
                continue;
            }
            $out[] = ['name' => $name, 'value' => $value];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return array{enabled: bool, origins: list<string>, methods: list<string>, headers: list<string>, allow_credentials: bool, max_age: int}
     */
    private function normalizeCors($raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'origins' => $this->stringList($raw['origins'] ?? []),
            'methods' => $this->stringList($raw['methods'] ?? ['GET', 'POST', 'OPTIONS']),
            'headers' => $this->stringList($raw['headers'] ?? ['Content-Type', 'Authorization']),
            'allow_credentials' => (bool) ($raw['allow_credentials'] ?? false),
            'max_age' => max(0, (int) ($raw['max_age'] ?? 3600)),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array{hostname: string, mode: string, dns_status: string, cname_target: string, verified_at: ?string, error: ?string}>
     */
    private function normalizeCustomDomains($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $hostname = strtolower(trim((string) ($entry['hostname'] ?? '')));
            if ($hostname === '') {
                continue;
            }
            $out[] = [
                'hostname' => $hostname,
                'mode' => (string) ($entry['mode'] ?? 'manual'),
                'dns_status' => (string) ($entry['dns_status'] ?? 'pending'),
                'cname_target' => (string) ($entry['cname_target'] ?? ''),
                'verified_at' => isset($entry['verified_at']) ? (string) $entry['verified_at'] : null,
                'error' => isset($entry['error']) ? (string) $entry['error'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function stringList($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function cacheKey(Site $site): string
    {
        return 'serverless:routing:'.$site->id;
    }
}
