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

    public static function isMysqlFamily(string $engine): bool
    {
        return in_array($engine, self::MYSQL_FAMILY, true);
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
