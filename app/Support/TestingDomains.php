<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

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

    public static function cloudflareApiToken(): string
    {
        return self::cloudflareTokens()[0] ?? '';
    }

    /**
     * Prefer a token that can actually see this zone. Queue workers often
     * still hold an older Edge token that is Zone:Read-only or scoped to
     * a different account than CLOUDFLARE_KEY.
     *
     * Never pick blindly by position: the first configured token is
     * CLOUDFLARE_KEY, which was historically provisioned for the MAIL
     * transport (see the note in config/services.php) and frequently belongs
     * to a different Cloudflare account than the one owning the testing
     * zones. That mismatch surfaces as "Zone [x] was not found in this
     * Cloudflare account" on an otherwise correct configuration.
     *
     * Results are cached per zone+token-set: this runs on the provisioning
     * path, each miss costs one Cloudflare round-trip per token tried, and
     * the answer only changes when a token or its Zone Resources change.
     * The token set is part of the key, so editing env invalidates it.
     */
    public static function cloudflareApiTokenForZone(string $zone): string
    {
        $zone = strtolower(trim($zone));
        $tokens = self::cloudflareTokens();
        if ($tokens === []) {
            return '';
        }

        if ($zone === '') {
            return $tokens[0];
        }

        $cacheKey = 'testing_domains:cf_token_for_zone:'.$zone.':'.substr(hash('sha256', implode('|', $tokens)), 0, 16);

        $resolved = Cache::remember(
            $cacheKey,
            now()->addMinutes(30),
            function () use ($tokens, $zone): ?string {
                foreach ($tokens as $token) {
                    try {
                        if ((new \App\Modules\Providers\Cloudflare\CloudflareDnsService($token))->zoneExists($zone)) {
                            return $token;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }

                // Cache the miss too — a zone nobody can see is usually a
                // config problem that will not fix itself within the TTL, and
                // re-probing every token on every provision is expensive.
                return null;
            },
        );

        return $resolved ?? $tokens[0];
    }

    /** Drop the cached zone→token decisions (after rotating a token). */
    public static function forgetCloudflareTokenForZone(string $zone = ''): void
    {
        $tokens = self::cloudflareTokens();
        if ($tokens === []) {
            return;
        }
        $suffix = substr(hash('sha256', implode('|', $tokens)), 0, 16);

        foreach ($zone !== '' ? [strtolower(trim($zone))] : self::vm() as $z) {
            Cache::forget('testing_domains:cf_token_for_zone:'.$z.':'.$suffix);
        }
    }

    /**
     * @return list<string>
     */
    public static function cloudflareTokens(): array
    {
        $tokens = [];
        foreach ([
            trim((string) config('services.cloudflare.key', '')),
            trim((string) config('testing_domains.cloudflare_api_token', '')),
            trim((string) config('edge.cloudflare.api_token', '')),
            trim((string) config('serverless.testing_dns.cloudflare_api_token', '')),
        ] as $token) {
            // Unexpanded `${VAR}` from .env is not a token — skip it so a
            // worker that missed interpolation does not send it as Bearer.
            if ($token === '' || str_starts_with($token, '${')) {
                continue;
            }
            if (! in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
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
