<?php

declare(strict_types=1);

namespace App\Support\Servers;

use Laravel\Pennant\Feature;

/**
 * Single source of truth for which database engines are generally available vs.
 * "coming soon". MySQL, MariaDB, PostgreSQL, and SQLite are always available;
 * MongoDB and ClickHouse are gated behind per-engine Pennant flags
 * (`database.{engine}`) so platform admin can flip them on per-org or
 * platform-wide — mirroring {@see CacheEngineAvailability}.
 *
 * Consumed everywhere an engine can be offered or installed:
 *   - the Databases workspace tab strip + engine panel (Soon badge + teaser),
 *   - the WorkspaceDatabases install guard,
 *   - the server-create database picker.
 */
final class DatabaseEngineAvailability
{
    /**
     * Engines gated behind a `database.{engine}` Pennant flag. Anything not
     * listed here (mysql, mariadb, postgres, sqlite) is always available.
     *
     * MariaDB graduated out of the gate — it ships alongside MySQL as one of
     * the two generally-available relational engines.
     *
     * @var list<string>
     */
    public const GATED_ENGINES = ['mongodb', 'clickhouse'];

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
        return "database.{$engine}";
    }

    /**
     * Server-create provision option ids (e.g. mariadb114) map to workspace
     * engine families before the coming-soon check runs.
     */
    public static function isProvisionOptionComingSoon(string $optionId): bool
    {
        $family = self::familyForProvisionOption($optionId);

        return $family !== null && self::isComingSoon($family);
    }

    public static function familyForProvisionOption(string $optionId): ?string
    {
        if (str_starts_with($optionId, 'mariadb')) {
            return 'mariadb';
        }

        if (in_array($optionId, ['mongodb', 'clickhouse'], true)) {
            return $optionId;
        }

        return null;
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
