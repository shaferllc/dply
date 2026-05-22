<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Curated metadata for each database engine dply manages. Mirrors
 * {@see CacheEngineInfo} so the cache-engine-info-card partial renders
 * unchanged.
 */
final class DatabaseEngineInfo
{
    /**
     * @return array<string, array{
     *   label: string,
     *   tagline: string,
     *   description: string,
     *   homepage_url: string,
     *   docs_url: string,
     *   license: string,
     *   maintainer: string,
     *   best_for: string,
     *   wire_protocol: string,
     *   first_released: string,
     * }>
     */
    public static function all(): array
    {
        return [
            'postgres' => [
                'label' => 'PostgreSQL',
                'tagline' => __('The most feature-rich open-source relational database.'),
                'description' => __('Advanced object-relational database with first-class JSON/JSONB types, robust transactional DDL, MVCC, full-text search, and a mature extension ecosystem (PostGIS, TimescaleDB, pgvector). The default pick for Laravel and Rails apps that want strong consistency + advanced query capabilities.'),
                'homepage_url' => 'https://www.postgresql.org',
                'docs_url' => 'https://www.postgresql.org/docs/',
                'license' => 'PostgreSQL License (similar to BSD/MIT)',
                'maintainer' => 'PostgreSQL Global Development Group',
                'best_for' => __('Modern web apps, analytics, geospatial data, anything that benefits from rich SQL features or extensions.'),
                'wire_protocol' => 'PostgreSQL wire protocol (v3)',
                'first_released' => '1996',
            ],
            'mysql' => [
                'label' => 'MySQL',
                'tagline' => __('The most widely deployed open-source database.'),
                'description' => __('Mature relational database with strong replication, InnoDB transactional storage, and broad framework support. Massive ecosystem of hosting + tooling. Owned by Oracle since 2010; the original branch most legacy PHP applications target.'),
                'homepage_url' => 'https://www.mysql.com',
                'docs_url' => 'https://dev.mysql.com/doc/',
                'license' => 'GPL-2.0 (community) / commercial (Oracle)',
                'maintainer' => 'Oracle Corporation',
                'best_for' => __('Legacy LAMP-stack apps, WordPress, applications expecting MySQL-specific SQL dialect or replication setup.'),
                'wire_protocol' => 'MySQL client/server protocol',
                'first_released' => '1995',
            ],
            'mariadb' => [
                'label' => 'MariaDB',
                'tagline' => __('Community fork of MySQL with extra features.'),
                'description' => __('Drop-in replacement for MySQL maintained by the original MySQL developers after Oracle acquired Sun. Wire-protocol compatible with MySQL clients; adds storage engines (Aria, ColumnStore), better defaults, and Galera Cluster integration out of the box.'),
                'homepage_url' => 'https://mariadb.org',
                'docs_url' => 'https://mariadb.com/kb/en/documentation/',
                'license' => 'GPL-2.0',
                'maintainer' => 'MariaDB Foundation',
                'best_for' => __('MySQL workloads where you want a community-governed governance model or the additional storage engines.'),
                'wire_protocol' => 'MySQL client/server protocol (compatible)',
                'first_released' => '2009',
            ],
        ];
    }

    public static function for(string $engine): array
    {
        return self::all()[$engine] ?? [
            'label' => ucfirst($engine),
            'tagline' => '',
            'description' => '',
            'homepage_url' => '',
            'docs_url' => '',
            'license' => '—',
            'maintainer' => '—',
            'best_for' => '',
            'wire_protocol' => '—',
            'first_released' => '—',
        ];
    }
}
