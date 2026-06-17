<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Optional PostgreSQL extensions installable from the Databases workspace (apt + CREATE EXTENSION).
 */
final class PostgresExtensionCatalog
{
    /** @var list<string> */
    public const KEYS = ['postgis', 'pgvector', 'timescaledb'];

    /**
     * @return array<string, array{
     *   label: string,
     *   description: string,
     *   extension: string,
     *   packages: list<string>,
     *   requires_repo: bool,
     * }>
     */
    public static function all(): array
    {
        return [
            'postgis' => [
                'label' => 'PostGIS',
                'description' => __('Geospatial types, indexes, and functions for location-aware apps.'),
                'extension' => 'postgis',
                'packages' => ['postgis', 'postgresql-postgis'],
                'requires_repo' => false,
            ],
            'pgvector' => [
                'label' => 'pgvector',
                'description' => __('Vector similarity search for embeddings and AI retrieval workloads.'),
                'extension' => 'vector',
                'packages' => ['postgresql-pgvector'],
                'requires_repo' => false,
            ],
            'timescaledb' => [
                'label' => 'TimescaleDB',
                'description' => __('Time-series hypertables and retention policies on top of PostgreSQL.'),
                'extension' => 'timescaledb',
                'packages' => ['timescaledb-2-postgresql-16', 'timescaledb-2-postgresql-15', 'timescaledb-2-postgresql-14'],
                'requires_repo' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function for(string $key): array
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown PostgreSQL extension: {$key}");
    }
}
