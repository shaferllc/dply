<?php

declare(strict_types=1);

namespace App\Support\Servers;

use RuntimeException;

/**
 * Normalize / coerce IaaS region slugs so a dedicated VM is not created in a
 * datacenter the provider no longer offers (e.g. DigitalOcean `sfo` → `sfo3`).
 */
final class ProviderComputeRegion
{
    public static function normalize(string $provider, string $region): string
    {
        $region = strtolower(trim($region));
        if ($region === '') {
            return $region;
        }

        if ($provider !== 'digitalocean') {
            return $region;
        }

        if (preg_match('/^[a-z]{3}[0-9]$/', $region) === 1) {
            return $region;
        }

        return match ($region) {
            'ams' => 'ams3',
            'nyc' => 'nyc3',
            'fra' => 'fra1',
            'sfo' => 'sfo3',
            'sgp' => 'sgp1',
            'lon' => 'lon1',
            'tor' => 'tor1',
            'blr' => 'blr1',
            'syd' => 'syd1',
            default => $region,
        };
    }

    /**
     * @param  list<string>  $availableSlugs
     */
    public static function coerceAvailable(string $provider, string $region, array $availableSlugs): string
    {
        $region = self::normalize($provider, $region);
        if ($region === '' || $availableSlugs === [] || in_array($region, $availableSlugs, true)) {
            return $region;
        }

        $prefix = (string) preg_replace('/\d+$/', '', $region);
        $siblings = array_values(array_filter(
            $availableSlugs,
            static fn (string $slug): bool => $prefix !== '' && str_starts_with($slug, $prefix),
        ));

        if ($siblings === []) {
            throw new RuntimeException(__('Region :region is not available on this provider account.', [
                'region' => $region,
            ]));
        }

        rsort($siblings);

        return $siblings[0];
    }
}
