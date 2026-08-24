<?php

declare(strict_types=1);

namespace App\Support;


/**
 * Dply-owned testing / preview zones from config/product/testing_domains.php.
 */
final class TestingDomains
{
    public static function provider(): string
    {
        $provider = strtolower(trim((string) config('testing_domains.provider', 'cloudflare')));

        return $provider !== '' ? $provider : 'cloudflare';
    }

    /**
     * The one Cloudflare token dply uses for its own testing zones.
     *
     * Deliberately a single config path (see config/product/testing_domains.php).
     * This used to consult four, in priority order, and return the first
     * non-empty one — which silently preferred a stale CLOUDFLARE_KEY over a
     * correctly-scoped token and made "which token is this?" unanswerable.
     */
    public static function cloudflareApiToken(): string
    {
        $token = trim((string) config('testing_domains.cloudflare_api_token', ''));

        // Unexpanded `${VAR}` from .env is not a token — never send it as a
        // Bearer, or Cloudflare answers 400 and the real cause stays hidden.
        return str_starts_with($token, '${') ? '' : $token;
    }

    /**
     * @return list<string>
     *
     * @deprecated There is exactly one token now; kept so callers that expected
     *             a list keep working. Prefer {@see self::cloudflareApiToken()}.
     */
    public static function cloudflareTokens(): array
    {
        $token = self::cloudflareApiToken();

        return $token === '' ? [] : [$token];
    }

    /**
     * Kept for call-site compatibility: with a single configured token there is
     * nothing left to choose between, so this is now just the token.
     *
     * The zone-probing this used to do (try each token, keep whichever could
     * see the zone) existed only to paper over the multi-variable mess. With
     * one token, a zone it cannot see is a real misconfiguration — and
     * CloudflareDnsService now says exactly which token failed and what it can
     * see, which is more useful than silently swapping to another one.
     */
    public static function cloudflareApiTokenForZone(string $zone): string
    {
        return self::cloudflareApiToken();
    }

    /**
     * Describe the configured token for an error message — never the token.
     */
    public static function describeCloudflareToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return 'no token';
        }

        $configured = self::cloudflareApiToken();
        if ($configured !== '' && hash_equals($configured, $token)) {
            return 'the platform token (CLOUDFLARE_API_TOKEN)';
        }

        return 'a token that is NOT the configured CLOUDFLARE_API_TOKEN '
            .'(a customer credential, or stale env on this worker)';
    }

    public static function cloudflareIsConfigured(): bool
    {
        return self::cloudflareApiToken() !== '';
    }

    public static function vmApex(): string
    {
        $apex = strtolower(trim((string) config('testing_domains.vm_apex', 'on-dply.cc')));

        return $apex !== '' ? $apex : 'on-dply.cc';
    }

    public static function edgeApex(): string
    {
        $apex = strtolower(trim((string) config('testing_domains.edge_apex', 'on-dply.site')));

        return $apex !== '' ? $apex : 'on-dply.site';
    }

    public static function serverlessApex(): string
    {
        $apex = strtolower(trim((string) config('testing_domains.serverless_apex', 'dply-serverless.cloud')));

        return $apex !== '' ? $apex : 'dply-serverless.cloud';
    }

    /**
     * @return list<string>
     */
    public static function vm(): array
    {
        return self::normalize((array) config('testing_domains.vm', []));
    }

    /**
     * @return list<string>
     */
    public static function edge(): array
    {
        return self::normalize((array) config('testing_domains.edge', []));
    }

    /**
     * @return list<string>
     */
    public static function serverless(): array
    {
        return self::normalize((array) config('testing_domains.serverless', []));
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(
            self::vm(),
            self::edge(),
            self::serverless(),
        )));
    }

    public static function zoneForHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        foreach (self::all() as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
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
