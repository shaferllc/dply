<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Models\Site;

/**
 * Shared helpers for the CloudBackend metrics/runtimeLogs methods —
 * window-code normalization, UNIX timestamp bounds, and the cache
 * keys the live-fetch wrappers use. Folded into the three backends
 * so the window vocabulary ('1h' / '6h' / '24h') and the cache TTL
 * stay identical across DigitalOcean, App Runner, and Fake.
 */
trait ResolvesMetricWindows
{
    /** Short TTL so dashboard renders don't hammer the provider API. */
    public const CACHE_TTL_SECONDS = 60;

    /**
     * Supported metric window codes, mapped to their length in
     * seconds. A method (not a trait constant) so it can be referenced
     * from compiled Blade views without the trait-constant access
     * restriction.
     *
     * @return array<string, int>
     */
    private static function windowSeconds(): array
    {
        return [
            '1h' => 3600,
            '6h' => 21600,
            '24h' => 86400,
        ];
    }

    /**
     * Coerce an arbitrary window string to a supported code,
     * defaulting to '1h' for anything unrecognized.
     */
    public function normalizeWindow(string $window): string
    {
        return isset(self::windowSeconds()[$window]) ? $window : '1h';
    }

    /**
     * UNIX [start, end] timestamp pair for a (normalized) window.
     *
     * @return array{0: int, 1: int}
     */
    public function windowBounds(string $window): array
    {
        $seconds = self::windowSeconds()[$this->normalizeWindow($window)];
        $end = time();

        return [$end - $seconds, $end];
    }

    /**
     * Valid window codes — used by the CLI / UI to render the picker.
     *
     * @return list<string>
     */
    public static function metricWindows(): array
    {
        return array_keys(self::windowSeconds());
    }

    public static function metricsCacheKey(Site $site, string $window): string
    {
        return 'cloud:metrics:'.$site->id.':'.$window;
    }

    public static function runtimeLogsCacheKey(Site $site, int $lines): string
    {
        return 'cloud:runtime-logs:'.$site->id.':'.$lines;
    }
}
