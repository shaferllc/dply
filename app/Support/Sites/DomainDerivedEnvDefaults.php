<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * Derive values for well-known env keys from a site's own hostname, so the
 * deploy gate can auto-fill them instead of blocking on a value dply already
 * knows (e.g. SESSION_DOMAIN). Prefers the site's primary domain; falls back to
 * the testing hostname (*.on-dply.…) when no primary domain has been added yet.
 */
final class DomainDerivedEnvDefaults
{
    /**
     * Keys we can confidently derive from the site host. Anything cookie/URL
     * shaped that Laravel keys off the app's own domain.
     *
     * @var list<string>
     */
    public const KEYS = [
        'SESSION_DOMAIN',
        'APP_URL',
        'ASSET_URL',
        'APP_DOMAIN',
        'SANCTUM_STATEFUL_DOMAINS',
    ];

    public static function isDerivable(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * The host to derive from: the primary domain, else the testing hostname.
     * Returns null when the site has neither yet. Any stray scheme/path on the
     * stored hostname is stripped so the result is a bare host.
     */
    public static function host(Site $site): ?string
    {
        $host = trim((string) ($site->primaryDomain()?->hostname ?? ''));
        if ($host === '') {
            $host = trim($site->testingHostname());
        }
        if ($host === '') {
            return null;
        }

        $host = preg_replace('#^https?://#i', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = strtolower(trim($host));

        return $host !== '' ? $host : null;
    }

    /**
     * Derived value for a single key, or null when the key isn't derivable or
     * the site has no host yet.
     */
    public static function for(Site $site, string $key): ?string
    {
        if (! self::isDerivable($key)) {
            return null;
        }

        $host = self::host($site);
        if ($host === null) {
            return null;
        }

        return match ($key) {
            // URL-shaped keys want a scheme; assume https (every dply site gets
            // a cert, and the loopback health check follows the http→https hop).
            'APP_URL', 'ASSET_URL' => 'https://'.$host,
            // Cookie/domain-shaped keys want the bare host.
            default => $host,
        };
    }

    /**
     * Map of {key: derived value} for every derivable key in $keys that we can
     * actually fill. Non-derivable keys (and keys with no host) are omitted, so
     * an empty result means "nothing to auto-fill".
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function resolve(Site $site, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = self::for($site, (string) $key);
            if ($value !== null && $value !== '') {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }
}
