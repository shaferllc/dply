<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Canonical engine keys for the server Databases workspace (tabs, capabilities, validation).
 */
final class DatabaseWorkspaceEngines
{
    /** @var list<string> */
    public const ENGINE_TABS = ['mysql', 'mariadb', 'postgres', 'mongodb', 'clickhouse', 'sqlite'];

    /** @var list<string> */
    public const WORKSPACE_TABS = [
        'databases', 'advanced', 'notifications',
        'mysql', 'mariadb', 'postgres', 'mongodb', 'clickhouse', 'sqlite',
    ];

    /** @var list<string> */
    public const MANAGEABLE = ['mysql', 'mariadb', 'postgres', 'mongodb', 'clickhouse'];

    /** @var list<string> */
    public const MYSQL_FAMILY = ['mysql', 'mariadb'];

    /** Engines with SQL-style backup export in v1. */
    /** @var list<string> */
    public const BACKUP_SUPPORTED = ['mysql', 'mariadb', 'postgres', 'sqlite', 'mongodb'];

    /**
     * Normalize a (possibly version-suffixed) engine identifier to its family,
     * e.g. "mysql84" → "mysql", "postgres18" → "postgres", "sqlite3" → "sqlite".
     * The installed-stack records versioned engine strings (mysql84), but the
     * provisioner branches on the family — without this, a scaffold-created DB
     * with engine "mysql84" fell through to "Unsupported database engine".
     */
    public static function family(string $engine): string
    {
        $engine = strtolower(trim($engine));

        return match (true) {
            str_starts_with($engine, 'maria') => 'mariadb',
            str_starts_with($engine, 'mysql') => 'mysql',
            str_starts_with($engine, 'postgres'), str_starts_with($engine, 'pgsql') => 'postgres',
            str_starts_with($engine, 'sqlite') => 'sqlite',
            str_starts_with($engine, 'mongo') => 'mongodb',
            str_starts_with($engine, 'clickhouse') => 'clickhouse',
            default => $engine,
        };
    }

    public static function isMysqlFamily(string $engine): bool
    {
        return in_array(self::family($engine), self::MYSQL_FAMILY, true);
    }

    public static function supportsBackup(string $engine): bool
    {
        return in_array($engine, self::BACKUP_SUPPORTED, true);
    }

    public static function defaultPortForEngine(string $engine): int
    {
        return DatabaseEngineInstallScripts::defaultPortFor($engine);
    }

    public static function label(string $engine): string
    {
        return match ($engine) {
            'mysql' => __('MySQL'),
            'mariadb' => __('MariaDB'),
            'postgres' => __('PostgreSQL'),
            'mongodb' => __('MongoDB'),
            'clickhouse' => __('ClickHouse'),
            'sqlite' => __('SQLite'),
            default => ucfirst($engine),
        };
    }

    /**
     * @return array{mysql: bool, mariadb: bool, postgres: bool, mongodb: bool, clickhouse: bool, sqlite: bool}
     */
    public static function defaultCapabilities(): array
    {
        return [
            'mysql' => false,
            'mariadb' => false,
            'postgres' => false,
            'mongodb' => false,
            'clickhouse' => false,
            'sqlite' => false,
        ];
    }
}
