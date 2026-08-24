<?php

declare(strict_types=1);

namespace App\Support\Providers;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Shared cache for provider region/size catalogs. Successful lists live for
 * hours (they rarely change). Transport failures trip {@see ProviderApiStatus}
 * instead of caching an empty list as success.
 */
final class ProviderCatalogCache
{
    public const SUCCESS_TTL_SECONDS = 21600;

    /**
     * @template T
     *
     * @param  callable(): T  $fetch
     * @return T
     */
    public static function remember(string $provider, string $kind, string $scope, callable $fetch): mixed
    {
        $key = self::key($provider, $kind, $scope);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            ProviderApiStatus::markReachable($provider);

            return $cached;
        }

        try {
            $items = $fetch();
        } catch (Throwable $exception) {
            if (ProviderCatalogFailure::isUnreachable($exception)) {
                ProviderApiStatus::markUnreachable($provider, $exception);
            }

            throw $exception;
        }

        if (is_array($items)) {
            Cache::put($key, $items, now()->addSeconds(self::SUCCESS_TTL_SECONDS));
            ProviderApiStatus::markReachable($provider);
        }

        return $items;
    }

    public static function forget(string $provider, string $kind, string $scope): void
    {
        Cache::forget(self::key($provider, $kind, $scope));
    }

    public static function forgetProvider(string $provider, string $scope = 'platform'): void
    {
        foreach (['regions', 'sizes', 'locations', 'server_types', 'plans', 'types', 'images', 'vpcs'] as $kind) {
            self::forget($provider, $kind, $scope);
        }
    }

    public static function scopeForToken(?string $token): string
    {
        $token = is_string($token) ? trim($token) : '';

        return $token === '' ? 'platform' : 'tok:'.sha1($token);
    }

    private static function key(string $provider, string $kind, string $scope): string
    {
        return 'provider_catalog:'.ProviderApiStatus::normalize($provider).':'.$kind.':'.$scope;
    }
}
