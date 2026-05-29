<?php

declare(strict_types=1);

namespace App\Support\Servers;

use Laravel\Pennant\Feature;

/**
 * Single source of truth for which cache engines are generally available vs.
 * "coming soon". Redis is always available; Valkey, Memcached, KeyDB, and
 * Dragonfly are gated behind per-engine Pennant flags (`cache.{engine}`) so
 * platform admin can flip them on per-org or platform-wide — mirroring the
 * coming-soon pattern used for the workspace previews.
 *
 * Consumed everywhere an engine can be offered or installed:
 *   - the Caches workspace tab strip + engine panel (Soon badge + teaser),
 *   - the WorkspaceCaches install guard,
 *   - the server-create cache-service / Valkey-role picker.
 */
final class CacheEngineAvailability
{
    /**
     * Engines gated behind a `cache.{engine}` Pennant flag. Anything not listed
     * here (redis, varnish) is always available.
     *
     * @var list<string>
     */
    public const GATED_ENGINES = ['valkey', 'memcached', 'keydb', 'dragonfly'];

    public static function isComingSoon(string $engine): bool
    {
        if (! in_array($engine, self::GATED_ENGINES, true)) {
            return false;
        }

        return ! Feature::active(self::flagFor($engine));
    }

    public static function isAvailable(string $engine): bool
    {
        return ! self::isComingSoon($engine);
    }

    public static function flagFor(string $engine): string
    {
        return "cache.{$engine}";
    }

    /**
     * Map of engine => coming-soon bool for the given engine list. Lets blade
     * views render Soon badges without re-resolving Pennant per iteration.
     *
     * @param  iterable<string>  $engines
     * @return array<string, bool>
     */
    public static function comingSoonMap(iterable $engines): array
    {
        $map = [];
        foreach ($engines as $engine) {
            $map[$engine] = self::isComingSoon($engine);
        }

        return $map;
    }
}
