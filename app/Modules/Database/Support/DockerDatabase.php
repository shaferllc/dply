<?php

declare(strict_types=1);

namespace App\Modules\Database\Support;

/**
 * Static facts for the "Docker container on this server" database placement.
 */
final class DockerDatabase
{
    /** @return list<string> */
    public static function supportedEngines(): array
    {
        return ['mysql', 'postgres', 'redis'];
    }

    public static function imageForEngine(string $engine): string
    {
        return match (strtolower($engine)) {
            'postgres', 'pgsql' => (string) config('server_database.docker.postgres_image', 'postgres:16-alpine'),
            'redis' => (string) config('server_database.docker.redis_image', 'redis:7-alpine'),
            default => (string) config('server_database.docker.mysql_image', 'mysql:8.0'),
        };
    }

    public static function containerPortForEngine(string $engine): int
    {
        return match (strtolower($engine)) {
            'postgres', 'pgsql' => 5432,
            'redis' => 6379,
            default => 3306,
        };
    }

    public static function hostPortBaseForEngine(string $engine): int
    {
        return match (strtolower($engine)) {
            'postgres', 'pgsql' => (int) config('server_database.docker.postgres_port_base', 54320),
            'redis' => (int) config('server_database.docker.redis_port_base', 63790),
            default => (int) config('server_database.docker.mysql_port_base', 33060),
        };
    }
}
