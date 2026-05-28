<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Laravel\Pennant\Feature;

final class AdminFeatureFlags
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function orgGroups(): array
    {
        return config('admin_feature_flags.org_groups', []);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function globalGroups(): array
    {
        return config('admin_feature_flags.global_groups', []);
    }

    /**
     * @return list<string>
     */
    public static function orgFlagKeys(): array
    {
        $keys = [];
        foreach (self::orgGroups() as $flags) {
            foreach (array_keys($flags) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function globalFlagKeys(): array
    {
        $keys = [];
        foreach (self::globalGroups() as $flags) {
            foreach (array_keys($flags) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function orgFlagLabel(string $key): ?string
    {
        foreach (self::orgGroups() as $flags) {
            if (isset($flags[$key])) {
                return $flags[$key];
            }
        }

        return null;
    }

    public static function globalFlagLabel(string $key): ?string
    {
        foreach (self::globalGroups() as $flags) {
            if (isset($flags[$key])) {
                return $flags[$key];
            }
        }

        return null;
    }

    public static function configDefault(string $key): bool
    {
        [$namespace, $leaf] = explode('.', $key, 2);

        return (bool) (config("features.{$namespace}.{$leaf}") ?? false);
    }

    public static function isGlobalNamespace(string $key): bool
    {
        return str_starts_with($key, 'global.');
    }

    public static function platformDefault(string $key): bool
    {
        return Feature::for(null)->active($key);
    }
}
