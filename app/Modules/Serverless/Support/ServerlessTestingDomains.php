<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

use App\Modules\Edge\Support\EdgeTestingDomains;

/**
 * Serverless function hostnames live on their own apex — dply-serverless.cloud
 * — so every deployed function answers at {slug}-{idHash8}.dply-serverless.cloud.
 * This is deliberately separate from the shared DPLY_TESTING_DOMAINS pool that
 * BYO/VM site previews draw from, and from the Edge on-dply.* delivery pool
 * ({@see EdgeTestingDomains}).
 *
 * Override with DPLY_SERVERLESS_TESTING_DOMAINS (comma-separated) when running
 * locally or on a staging apex.
 */
final class ServerlessTestingDomains
{
    public const DEFAULT_APEX = 'dply-serverless.cloud';

    /**
     * The apexes a *new* function hostname may be minted on.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $domains = self::normalize((array) config('serverless.testing_domains', []));

        return $domains === [] ? [self::DEFAULT_APEX] : $domains;
    }

    public static function defaultApex(): string
    {
        return self::all()[0];
    }

    /**
     * Every apex a function hostname may currently resolve on — the serverless
     * pool plus the legacy shared DPLY_TESTING_DOMAINS pool. Functions minted
     * before the dedicated apex existed still carry a `{slug}.dply.cc`-style
     * host in DNS, so the proxy must keep answering there even though nothing
     * new is minted on it.
     *
     * @return list<string>
     */
    public static function routable(): array
    {
        return array_values(array_unique(array_merge(
            self::all(),
            self::legacyPool(),
        )));
    }

    /**
     * Deterministic apex for a function, keyed on its site id so a given
     * function's hostname never moves between apexes on its own.
     */
    public static function apexFor(int|string $siteKey): string
    {
        $domains = self::all();

        return $domains[abs(crc32((string) $siteKey)) % count($domains)];
    }

    /**
     * The zone a function hostname sits under, or null when the host isn't on
     * any apex dply controls. Matches against {@see routable()} so DNS repair
     * still works for legacy-pool hostnames.
     */
    public static function zoneForHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        foreach (self::routable() as $domain) {
            if (str_ends_with($host, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * True when this zone is served by a hand-created `*.{apex}` record, so
     * dply should not touch the DNS API at all — the hostname is already live
     * the moment the slug exists.
     *
     * Only the serverless apex can be in wildcard mode; legacy shared-pool
     * zones carry per-site records and always go through the provider API.
     */
    public static function usesWildcard(string $zone): bool
    {
        if (! in_array(strtolower(trim($zone)), self::all(), true)) {
            return false;
        }

        return strtolower(trim((string) config('serverless.testing_dns.mode', 'wildcard'))) === 'wildcard';
    }

    /**
     * Which DNS API writes records for a hostname on this zone.
     *
     * The serverless apex is a Cloudflare zone, so its records go through the
     * Cloudflare API. The legacy shared pool (dply.cc, dply.host, …) lives on
     * DigitalOcean and keeps using the DO path, so repairing an old hostname
     * still works after the switch.
     */
    public static function dnsProviderForZone(string $zone): string
    {
        $zone = strtolower(trim($zone));

        if (in_array($zone, self::all(), true)) {
            $provider = strtolower(trim((string) config('serverless.testing_dns.provider', 'cloudflare')));

            return $provider !== '' ? $provider : 'cloudflare';
        }

        return 'digitalocean';
    }

    public static function cloudflareApiToken(): string
    {
        return trim((string) config('serverless.testing_dns.cloudflare_api_token', ''));
    }

    /**
     * @return list<string>
     */
    private static function legacyPool(): array
    {
        return self::normalize((array) config('services.digitalocean.testing_domains', []));
    }

    /**
     * @param  array<int|string, mixed>  $domains
     * @return list<string>
     */
    private static function normalize(array $domains): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? strtolower(trim($value)) : '',
            $domains,
        ))));
    }
}
