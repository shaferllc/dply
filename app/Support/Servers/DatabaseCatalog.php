<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Services\Servers\ServerDatabaseRemoteExec;

/**
 * How to ask each engine what databases it holds, and which of the names it
 * answers with are the engine's own plumbing rather than someone's data.
 *
 * Split out from {@see ServerDatabaseRemoteExec} so the
 * query strings and the system-name lists — the parts that are easy to get
 * subtly wrong and that need to change per engine version — are unit-testable
 * without an SSH connection.
 *
 * sqlite is absent deliberately: a sqlite database is a file at an arbitrary
 * path with no catalog to query, and the only directory dply could scan
 * ({@see config('server_database.sqlite_root')}) holds exactly the databases
 * dply itself created there, which are tracked by definition.
 */
final class DatabaseCatalog
{
    /** Engines whose databases can be enumerated at all. */
    public const ENUMERABLE = ['postgres', 'mysql', 'mariadb', 'mongodb', 'clickhouse'];

    /**
     * Names each engine ships with. Excluded from discovery because adopting
     * them would be meaningless at best and destructive at worst — `mysql` and
     * `postgres` hold the server's own catalogs.
     *
     * @var array<string, list<string>>
     */
    private const SYSTEM_NAMES = [
        'postgres' => ['postgres', 'template0', 'template1'],
        'mysql' => ['information_schema', 'performance_schema', 'mysql', 'sys'],
        'mariadb' => ['information_schema', 'performance_schema', 'mysql', 'sys'],
        'mongodb' => ['admin', 'config', 'local'],
        'clickhouse' => ['system', 'information_schema', 'default'],
    ];

    public static function supports(string $engine): bool
    {
        return in_array(DatabaseWorkspaceEngines::family($engine), self::ENUMERABLE, true);
    }

    /**
     * The statement that lists database names, one per line, no headers.
     * Null for an engine we do not enumerate.
     */
    public static function listStatementFor(string $engine): ?string
    {
        return match (DatabaseWorkspaceEngines::family($engine)) {
            'postgres' => 'SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname',
            'mysql', 'mariadb' => 'SHOW DATABASES',
            // getDBNames() returns a plain array; joining keeps the output in the
            // same one-name-per-line shape as every other engine, instead of the
            // nested document listDatabases returns.
            'mongodb' => 'db.getMongo().getDBNames().join("\n")',
            'clickhouse' => 'SHOW DATABASES',
            default => null,
        };
    }

    public static function isSystemDatabase(string $engine, string $name): bool
    {
        $family = DatabaseWorkspaceEngines::family($engine);
        $names = self::SYSTEM_NAMES[$family] ?? [];

        return in_array(strtolower(trim($name)), $names, true);
    }

    /**
     * Turn raw command output into the list of real database names.
     *
     * Tolerant of the noise each client adds — blank lines, mongosh banners,
     * psql notices — by keeping only lines that look like a bare identifier.
     * Anything else is dropped rather than adopted as a database called
     * "Warning: ...".
     *
     * @return list<string>
     */
    public static function parseNames(string $engine, string $raw): array
    {
        $names = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || self::isSystemDatabase($engine, $line)) {
                continue;
            }
            // A database name here is an identifier the engine printed on its
            // own line. Client chatter (warnings, connection notices, table
            // borders) contains spaces or punctuation and is filtered out.
            if (preg_match('/^[A-Za-z0-9_$-]{1,128}$/', $line) !== 1) {
                continue;
            }
            if (! in_array($line, $names, true)) {
                $names[] = $line;
            }
        }

        return $names;
    }
}
