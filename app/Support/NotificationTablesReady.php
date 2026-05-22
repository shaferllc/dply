<?php

declare(strict_types=1);

namespace App\Support;

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

    /** Single-table check, memoised per request. */
    public static function has(string $table): bool
    {
        if (array_key_exists($table, self::$cache)) {
            return self::$cache[$table];
        }

        return self::$cache[$table] = Schema::hasTable($table);
    }

    /** Drop the memo (between requests in long-running processes / tests). */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
