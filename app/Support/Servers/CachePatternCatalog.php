<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Curated SCAN-MATCH glob examples for the key-browser Pattern field.
 *
 * SCAN MATCH supports a small glob dialect (the engines call it "Redis glob style"): `*`, `?`,
 * `[set]`, `[a-z]`, with `\` escaping. Operators rarely remember the syntax verbatim, so the
 * key browser surfaces these as autocomplete suggestions and a reference modal.
 *
 * `pattern` is the literal text dropped into the Pattern input. `description` explains what
 * keys it matches in plain English. `group` controls which section it appears in.
 */
class CachePatternCatalog
{
    public const GROUP_COMMON = 'Common patterns';

    public const GROUP_GLOB = 'Glob building blocks';

    public const GROUP_LARAVEL = 'Laravel-style keys';

    /**
     * @return list<array{pattern: string, description: string, group: string}>
     */
    public static function all(): array
    {
        return [
            // Common patterns
            ['pattern' => '*', 'description' => 'Every key in the database. Pages through the full keyspace.', 'group' => self::GROUP_COMMON],
            ['pattern' => 'session:*', 'description' => 'All keys under the "session:" prefix.', 'group' => self::GROUP_COMMON],
            ['pattern' => 'cache:*', 'description' => 'All keys under the "cache:" prefix.', 'group' => self::GROUP_COMMON],
            ['pattern' => 'queues:*', 'description' => 'All Horizon / Laravel queue keys.', 'group' => self::GROUP_COMMON],
            ['pattern' => '*:lock', 'description' => 'Anything ending in ":lock" — Laravel cache locks, mutexes, etc.', 'group' => self::GROUP_COMMON],
            ['pattern' => '*temporary*', 'description' => 'Keys containing "temporary" anywhere in the name.', 'group' => self::GROUP_COMMON],

            // Laravel-style keys (very common in apps deployed via dply)
            ['pattern' => 'laravel_cache_*', 'description' => 'Default Laravel cache prefix (config: cache.prefix).', 'group' => self::GROUP_LARAVEL],
            ['pattern' => 'laravel_database_*', 'description' => 'Default Laravel queue/cache key prefix on Redis store.', 'group' => self::GROUP_LARAVEL],
            ['pattern' => 'laravel_session_*', 'description' => 'Default Laravel session key prefix.', 'group' => self::GROUP_LARAVEL],
            ['pattern' => 'illuminate:*', 'description' => 'Illuminate\\Cache and Illuminate\\Queue scoped keys.', 'group' => self::GROUP_LARAVEL],
            ['pattern' => 'horizon:*', 'description' => 'Laravel Horizon supervisors, jobs, metrics.', 'group' => self::GROUP_LARAVEL],

            // Glob syntax primer
            ['pattern' => '?ession', 'description' => '? matches exactly one character — "session", "Session", "wession", etc.', 'group' => self::GROUP_GLOB],
            ['pattern' => '[sS]ession*', 'description' => '[set] matches any single character in the set.', 'group' => self::GROUP_GLOB],
            ['pattern' => 'user:[0-9]*', 'description' => '[a-z] matches a character range. Combine with * for "anything starting with".', 'group' => self::GROUP_GLOB],
            ['pattern' => '\\*literal', 'description' => '\\ escapes a glob special character so * matches a literal asterisk.', 'group' => self::GROUP_GLOB],
        ];
    }

    /**
     * Same data, grouped for the reference modal's section layout.
     *
     * @return array<string, list<array{pattern: string, description: string, group: string}>>
     */
    public static function byGroup(): array
    {
        $grouped = [];
        foreach (self::all() as $entry) {
            $grouped[$entry['group']][] = $entry;
        }

        return $grouped;
    }
}
