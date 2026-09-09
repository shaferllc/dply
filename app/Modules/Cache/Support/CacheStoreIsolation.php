<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

/**
 * Whether the cache item store actually lives apart from the control plane.
 *
 * The same condition `QueueStoreIsolation` reports, and for a sharper reason:
 * `config/database.php` defines a `dply_cache` connection whose every key falls
 * through to the primary `DB_*` when the `DPLY_CACHE_DB_*` overrides are unset,
 * and a cache is higher churn than a queue. Nothing announces that, so the
 * default state is a shared database that looks separate everywhere in the code.
 *
 * Sharing is not a misconfiguration to reject — it is a perfectly reasonable
 * way to run a small install, and failing closed would take the product down
 * for people it is working for. It is a condition to *surface*.
 *
 * The mitigation that does apply either way: `dply_cache_items` is UNLOGGED, so
 * even on a shared instance cache writes generate no WAL. What they still share
 * is buffer cache, autovacuum workers, and connection slots.
 */
final class CacheStoreIsolation
{
    public const CONNECTION = 'dply_cache';

    public static function isSeparate(): bool
    {
        return self::descriptor(self::CONNECTION) !== self::descriptor((string) config('database.default'));
    }

    public static function summary(): string
    {
        $descriptor = self::descriptor(self::CONNECTION);

        return self::isSeparate()
            ? $descriptor.' (separate from the control plane)'
            : $descriptor.' (SHARED with the control plane)';
    }

    public static function advice(): ?string
    {
        if (self::isSeparate()) {
            return null;
        }

        return 'Cache reads and writes are hitting the same Postgres as the dashboard, sites and billing. '
            .'The items table is UNLOGGED so this costs no WAL, but it still shares buffer cache, autovacuum '
            .'workers and connection slots. Point DPLY_CACHE_DB_HOST / _PORT / _DATABASE / _USERNAME / _PASSWORD '
            .'at a separate database and run `php artisan migrate`. Moving it is cheapest before real traffic exists.';
    }

    /**
     * host:port/database, resolved through the same config the driver reads —
     * including a DSN `url`, which overrides the discrete keys and would
     * otherwise make two different databases look identical.
     */
    private static function descriptor(string $connection): string
    {
        $config = (array) config('database.connections.'.$connection, []);

        $url = trim((string) ($config['url'] ?? ''));
        if ($url !== '') {
            $parts = parse_url($url);

            if (is_array($parts)) {
                return ($parts['host'] ?? '?')
                    .':'.($parts['port'] ?? '5432')
                    .'/'.ltrim((string) ($parts['path'] ?? ''), '/');
            }
        }

        return (string) ($config['host'] ?? '?')
            .':'.(string) ($config['port'] ?? '?')
            .'/'.(string) ($config['database'] ?? '?');
    }
}
