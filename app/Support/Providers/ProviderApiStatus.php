<?php

declare(strict_types=1);

namespace App\Support\Providers;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Circuit for “can we reach this cloud provider’s API?”
 *
 * A transport failure (timeout, DNS, connection reset) pauses create for
 * that provider — not a stale customer token. Cached so Livewire does not
 * re-hit a dead API on every click.
 */
final class ProviderApiStatus
{
    public const DOWN_TTL_SECONDS = 300;

    public static function isUnreachable(string $provider): bool
    {
        return Cache::get(self::key($provider)) === 'down';
    }

    public static function markUnreachable(string $provider, Throwable|string|null $error = null): void
    {
        $provider = self::normalize($provider);
        if ($provider === '' || $provider === 'custom') {
            return;
        }

        Cache::put(self::key($provider), 'down', now()->addSeconds(self::DOWN_TTL_SECONDS));
        Cache::put(
            self::reasonKey($provider),
            ProviderCatalogFailure::sanitize(
                $error instanceof Throwable ? $error->getMessage() : $error,
                $provider,
            ),
            now()->addSeconds(self::DOWN_TTL_SECONDS),
        );
    }

    public static function markReachable(string $provider): void
    {
        $provider = self::normalize($provider);
        Cache::forget(self::key($provider));
        Cache::forget(self::reasonKey($provider));
    }

    public static function forget(string $provider): void
    {
        self::markReachable($provider);
    }

    public static function operatorMessage(string $provider): string
    {
        $cached = Cache::get(self::reasonKey($provider));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return ProviderCatalogFailure::message($provider);
    }

    public static function normalize(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return match ($provider) {
            'digitalocean_kubernetes', 'digitalocean_functions' => 'digitalocean',
            'aws_kubernetes', 'aws_lambda', 'aws_app_runner' => 'aws',
            default => $provider,
        };
    }

    private static function key(string $provider): string
    {
        return 'provider_api:status:'.self::normalize($provider);
    }

    private static function reasonKey(string $provider): string
    {
        return 'provider_api:reason:'.self::normalize($provider);
    }
}
