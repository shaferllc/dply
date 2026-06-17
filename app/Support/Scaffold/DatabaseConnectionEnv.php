<?php

declare(strict_types=1);

namespace App\Support\Scaffold;

/**
 * Single source of truth for database-related Laravel `.env` blocks.
 *
 * Replaces the hardcoded `DB_CONNECTION=mysql` template that lived in
 * ScaffoldLaravelPipeline (and which broke as soon as the wizard or
 * the script picked anything other than mysql). Adding a new engine
 * means adding one branch here and nowhere else.
 *
 * Each engine returns a multi-line block (newline-terminated lines)
 * that gets dropped into the .env body by the scaffolding pipeline.
 * The block contract: every line is a single `KEY=value` pair, no
 * leading whitespace, terminated with `\n`.
 */
final class DatabaseConnectionEnv
{
    /**
     * Return the .env block for the given engine.
     *
     * @param  array{
     *     name?: string,
     *     username?: string,
     *     password?: string,
     *     host?: string,
     *     port?: int|string,
     *     sqlite_path?: string,
     * }  $context
     */
    public static function forEngine(string $engine, array $context): string
    {
        return match (true) {
            $engine === 'sqlite3' || str_starts_with($engine, 'sqlite') => self::sqlite($context),
            str_starts_with($engine, 'postgres') => self::postgres($context),
            str_starts_with($engine, 'mariadb') => self::mariadb($context),
            // Default: mysql variants (mysql57, mysql80, mysql84, mysql).
            // Falling through here is intentional — we want unknown
            // mysql-family engines to still produce a sensible block
            // rather than nothing. New engines that AREN'T mysql-like
            // (timescaledb, cockroachdb) need an explicit branch above.
            default => self::mysql($context),
        };
    }

    /** @param  array<string, mixed> $context */
    private static function sqlite(array $context): string
    {
        $path = (string) ($context['sqlite_path'] ?? 'database/database.sqlite');

        // Laravel auto-creates the file on first migrate when the path
        // doesn't exist. No host/port/credentials concept exists for
        // SQLite, so the block is intentionally minimal — leaving the
        // mysql-style keys out (vs setting them to empty strings) keeps
        // .env files self-documenting and avoids confusion later.
        return "DB_CONNECTION=sqlite\nDB_DATABASE={$path}\n";
    }

    /** @param  array<string, mixed> $context */
    private static function mysql(array $context): string
    {
        $host = (string) ($context['host'] ?? '127.0.0.1');
        $port = (string) ($context['port'] ?? '3306');
        $name = (string) ($context['name'] ?? '');
        $user = (string) ($context['username'] ?? '');
        $pass = (string) ($context['password'] ?? '');

        return "DB_CONNECTION=mysql\nDB_HOST={$host}\nDB_PORT={$port}\nDB_DATABASE={$name}\nDB_USERNAME={$user}\nDB_PASSWORD={$pass}\n";
    }

    /** @param  array<string, mixed> $context */
    private static function mariadb(array $context): string
    {
        $host = (string) ($context['host'] ?? '127.0.0.1');
        $port = (string) ($context['port'] ?? '3306');
        $name = (string) ($context['name'] ?? '');
        $user = (string) ($context['username'] ?? '');
        $pass = (string) ($context['password'] ?? '');

        // Laravel 11+ added a dedicated `mariadb` driver. Older Laravel
        // versions still treat MariaDB as a MySQL-protocol-compatible
        // engine and accept DB_CONNECTION=mysql. Using the dedicated
        // driver lets newer Laravel apps benefit from MariaDB-specific
        // features (e.g., RETURNING clauses).
        return "DB_CONNECTION=mariadb\nDB_HOST={$host}\nDB_PORT={$port}\nDB_DATABASE={$name}\nDB_USERNAME={$user}\nDB_PASSWORD={$pass}\n";
    }

    /** @param  array<string, mixed> $context */
    private static function postgres(array $context): string
    {
        $host = (string) ($context['host'] ?? '127.0.0.1');
        $port = (string) ($context['port'] ?? '5432');
        $name = (string) ($context['name'] ?? '');
        $user = (string) ($context['username'] ?? '');
        $pass = (string) ($context['password'] ?? '');

        return "DB_CONNECTION=pgsql\nDB_HOST={$host}\nDB_PORT={$port}\nDB_DATABASE={$name}\nDB_USERNAME={$user}\nDB_PASSWORD={$pass}\n";
    }
}
