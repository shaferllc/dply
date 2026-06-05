<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request memoised "are the notification tables migrated yet?" check.
 *
 * Several places (site-header.blade.php, Livewire\Notifications\ResourceSummary,
 * Livewire\Notifications\Index) need to gate UI on whether
 * `notification_inbox_items` and `notification_events` exist. Each
 * `Schema::hasTable()` call is a real round-trip to the information schema —
 * the debug bar showed two of these per render, sometimes more, for the same
 * pair of tables.
 *
 * This helper caches the booleans for the lifetime of the request so a single
 * boot-up question hits the database once.
 */
final class NotificationTablesReady
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /** Both event + inbox tables present (used by gates that read either). */
    public static function all(): bool
    {
        return self::has('notification_events') && self::has('notification_inbox_items');
    }

    /** Single-table check, memoised per request and (once true) persistently. */
    public static function has(string $table): bool
    {
        if (array_key_exists($table, self::$cache)) {
            return self::$cache[$table];
        }

        // A migrated table is never dropped, so a positive result is cached
        // persistently — once seen, future requests skip the ~15ms
        // information_schema round-trip entirely. A negative result is only
        // memoised per request, so a pending migration is picked up on the
        // next request instead of being pinned to "missing" forever.
        if (Cache::get(self::cacheKey($table)) === true) {
            return self::$cache[$table] = true;
        }

        $exists = Schema::hasTable($table);
        if ($exists) {
            Cache::forever(self::cacheKey($table), true);
        }

        return self::$cache[$table] = $exists;
    }

    /** Drop the memo (between requests in long-running processes / tests). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Forget the persistent positive cache for a table (tests / teardown). */
    public static function forget(string $table): void
    {
        unset(self::$cache[$table]);
        Cache::forget(self::cacheKey($table));
    }

    private static function cacheKey(string $table): string
    {
        return "schema.table-exists.{$table}";
    }
}
