<?php

declare(strict_types=1);

namespace App\Support\Servers;

use RuntimeException;

/**
 * Normalize IaaS region slugs, then constrain them to the live
 * managed-database catalog the caller already fetched.
 *
 * Short city codes (`sfo`, `nyc`) remap via the compute catalog. Numbered
 * slugs stay as-is — only `$available` from /v2/databases/options decides
 * whether a DC is valid for create.
 */
final class ProviderManagedDatabaseRegion
{
    public static function normalize(string $provider, string $region): string
    {
        return ProviderComputeRegion::normalize($provider, $region);
    }

    /**
     * Prefer an operator-picked region, then the server fallback, then a
     * catalog slug. Never returns a slug that is not in $available when that
     * list is non-empty.
     *
     * @param  list<string>  $available
     */
    public static function resolve(string $provider, ?string $requested, ?string $fallback, array $available = []): ?string
    {
        $region = trim((string) (filled($requested) ? $requested : $fallback));
        $normalized = $region !== '' ? self::normalize($provider, $region) : '';

        if ($available === []) {
            return $normalized !== '' ? $normalized : null;
        }

        if ($normalized !== '' && in_array($normalized, $available, true)) {
            return $normalized;
        }

        if ($region !== '' && in_array($region, $available, true)) {
            return $region;
        }

        if ($normalized !== '') {
            try {
                $coerced = ProviderComputeRegion::coerceAvailable($provider, $normalized, $available);
                if (in_array($coerced, $available, true)) {
                    return $coerced;
                }
            } catch (RuntimeException) {
                // Fall through to a catalog default.
            }
        }

        foreach (['nyc3', 'nyc1', 'ams3'] as $preferred) {
            if (in_array($preferred, $available, true)) {
                return $preferred;
            }
        }

        return $available[0] ?? null;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $provider, array $slugs = []): array
    {
        if ($provider !== 'digitalocean' || $slugs === []) {
            return [];
        }

        return array_map(
            static fn (string $slug): array => [
                'value' => $slug,
                'label' => self::label($slug),
            ],
            array_values(array_unique($slugs)),
        );
    }

    /**
     * Keep the live catalog as-is. Only drop slugs the create API already
     * rejected so retry does not re-pick the same DC.
     *
     * @param  list<string>  $slugs
     * @param  list<string>  $rejected
     * @return list<string>
     */
    public static function filterForEngine(string $engine, array $slugs, array $rejected = []): array
    {
        unset($engine);

        $deny = [];
        foreach ($rejected as $slug) {
            $slug = strtolower(trim((string) $slug));
            if ($slug !== '') {
                $deny[] = $slug;
            }
        }

        $normalized = [];
        foreach ($slugs as $slug) {
            if (! is_string($slug)) {
                continue;
            }
            $slug = strtolower(trim($slug));
            if ($slug !== '') {
                $normalized[] = $slug;
            }
        }

        return array_values(array_filter(
            array_unique($normalized),
            static fn (string $slug): bool => ! in_array($slug, $deny, true),
        ));
    }

    public static function rejectedFromError(?string $error): array
    {
        if (! is_string($error) || $error === '') {
            return [];
        }

        if (preg_match("/region '([a-z0-9]+)' is not valid/i", $error, $matches) !== 1) {
            return [];
        }

        return [strtolower($matches[1])];
    }

    public static function label(string $slug): string
    {
        $metro = (string) preg_replace('/\d+$/', '', $slug);

        $city = match ($metro) {
            'ams' => __('Amsterdam'),
            'blr' => __('Bangalore'),
            'fra' => __('Frankfurt'),
            'lon' => __('London'),
            'nyc' => __('New York'),
            'sfo' => __('San Francisco'),
            'sgp' => __('Singapore'),
            'syd' => __('Sydney'),
            'tor' => __('Toronto'),
            'atl' => __('Atlanta'),
            'ric' => __('Richmond'),
            default => strtoupper($metro),
        };

        return $city.' · '.$slug;
    }
}
