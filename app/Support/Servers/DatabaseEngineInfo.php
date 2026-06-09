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
            'mongodb' => [
                'label' => 'MongoDB',
                'tagline' => __('Flexible document database for JSON-shaped application data.'),
                'description' => __('Document-oriented NoSQL database with rich query language, replica sets, and sharding. Ideal for catalogs, content, mobile backends, and workloads that evolve schema without migrations.'),
                'homepage_url' => 'https://www.mongodb.com',
                'docs_url' => 'https://www.mongodb.com/docs/',
                'license' => 'SSPL / commercial (Atlas)',
                'maintainer' => 'MongoDB Inc.',
                'best_for' => __('Document stores, real-time analytics, MEAN/MERN stacks, flexible schemas.'),
                'wire_protocol' => 'MongoDB wire protocol',
                'first_released' => '2009',
            ],
            'clickhouse' => [
                'label' => 'ClickHouse',
                'tagline' => __('Columnar OLAP database for analytics at scale.'),
                'description' => __('High-performance column-oriented DBMS for real-time analytics, event pipelines, and Fleet-style observability on your own VM. Complements row-store PostgreSQL/MySQL rather than replacing them.'),
                'homepage_url' => 'https://clickhouse.com',
                'docs_url' => 'https://clickhouse.com/docs',
                'license' => 'Apache-2.0',
                'maintainer' => 'ClickHouse Inc.',
                'best_for' => __('Log analytics, metrics rollups, wide event tables, BI queries over billions of rows.'),
                'wire_protocol' => 'ClickHouse native / HTTP',
                'first_released' => '2016',
            ],
            'sqlite' => [
                'label' => 'SQLite',
                'tagline' => __('Embedded SQL engine in a single file.'),
                'description' => __('Serverless, file-based relational database with zero configuration and no separate daemon. Ideal for small apps, local caches, tests, and workloads that fit in one VM file under dply\'s managed sqlite root. Backups copy the `.db` file; SQL runs via sqlite3 over SSH.'),
                'homepage_url' => 'https://sqlite.org',
                'docs_url' => 'https://sqlite.org/docs.html',
                'license' => 'Public domain',
                'maintainer' => 'SQLite Consortium',
                'best_for' => __('Lightweight apps, embedded data, single-tenant file storage on the same server as your sites.'),
                'wire_protocol' => __('File API (sqlite3 CLI)'),
                'first_released' => '2000',
            ],
        ];
    }

    /**
     * Terminal-hero + feature content for the shared <x-workspace-coming-soon>
     * teaser rendered on a gated engine's workspace tabs. Keyed by engine
     * family; falls back to a generic relational preview for anything without
     * bespoke copy. Mirrors the line/feature shape the component expects.
     *
     * @return array{
     *   icon: string,
     *   eyebrow: string,
     *   lines: array<int, array{tone: string, text: string}>,
     *   features: array<int, array{icon: string, title: string, body: string}>,
     * }
     */
    public static function comingSoonPreview(string $engine): array
    {
        return match (DatabaseWorkspaceEngines::family($engine)) {
            'mariadb' => [
                'icon' => 'heroicon-o-circle-stack',
                'eyebrow' => __('MariaDB install preview'),
                'lines' => [
                    ['tone' => 'cmd', 'text' => '~ $ apt-get install -y mariadb-server'],
                    ['tone' => 'muted', 'text' => 'Setting up mariadb-server (11.4) …'],
                    ['tone' => 'muted', 'text' => 'CREATE DATABASE app; CREATE USER \'app\'@\'%\' …'],
                    ['tone' => 'ok', 'text' => 'MariaDB ready · listening on :3306'],
                ],
                'features' => [
                    ['icon' => 'circle-stack', 'title' => __('MySQL-compatible'), 'body' => __('Drop-in wire-protocol compatibility with every MySQL client and ORM.')],
                    ['icon' => 'bolt', 'title' => __('Extra storage engines'), 'body' => __('Aria, ColumnStore, and Galera Cluster available out of the box.')],
                    ['icon' => 'key', 'title' => __('Managed users & grants'), 'body' => __('Create databases and scoped users from the workspace, provisioned over SSH.')],
                    ['icon' => 'arrow-uturn-left', 'title' => __('Backups & restore'), 'body' => __('Scheduled SQL dumps to your S3-style destination with one-click restore.')],
                ],
            ],
            'mongodb' => [
                'icon' => 'heroicon-o-circle-stack',
                'eyebrow' => __('MongoDB install preview'),
                'lines' => [
                    ['tone' => 'cmd', 'text' => '~ $ systemctl start mongod'],
                    ['tone' => 'muted', 'text' => 'MongoDB 7.0 listening on :27017'],
                    ['tone' => 'muted', 'text' => 'db.createUser({ user: "app", roles: ["readWrite"] })'],
                    ['tone' => 'ok', 'text' => 'replica set ready · 1 database'],
                ],
                'features' => [
                    ['icon' => 'document-text', 'title' => __('Document model'), 'body' => __('Store JSON-shaped data with a rich query language — no migrations to evolve schema.')],
                    ['icon' => 'key', 'title' => __('Users & roles'), 'body' => __('Provision scoped database users with readWrite/admin roles over SSH.')],
                    ['icon' => 'signal', 'title' => __('Remote access'), 'body' => __('Expose per-database connectivity with firewalled remote access when you need it.')],
                    ['icon' => 'arrow-uturn-left', 'title' => __('Backups'), 'body' => __('mongodump exports streamed to your backup destination.')],
                ],
            ],
            'clickhouse' => [
                'icon' => 'heroicon-o-chart-bar',
                'eyebrow' => __('ClickHouse install preview'),
                'lines' => [
                    ['tone' => 'cmd', 'text' => '~ $ clickhouse-client'],
                    ['tone' => 'muted', 'text' => 'SELECT count() FROM events  -- 4.2B rows'],
                    ['tone' => 'muted', 'text' => '↳ 0.18s · 23.41 GB/s'],
                    ['tone' => 'ok', 'text' => 'columnar OLAP ready · :8123 / :9000'],
                ],
                'features' => [
                    ['icon' => 'chart-bar', 'title' => __('Columnar OLAP'), 'body' => __('Sub-second aggregates over billions of rows for analytics and observability.')],
                    ['icon' => 'bolt', 'title' => __('Real-time ingest'), 'body' => __('High-throughput event pipelines alongside your row-store databases.')],
                    ['icon' => 'key', 'title' => __('Managed users'), 'body' => __('Create scoped ClickHouse users and databases from the workspace.')],
                    ['icon' => 'cloud-arrow-up', 'title' => __('Backups'), 'body' => __('Export and restore to your S3-style backup destination.')],
                ],
            ],
            default => [
                'icon' => 'heroicon-o-circle-stack',
                'eyebrow' => __(':engine install preview', ['engine' => self::for($engine)['label']]),
                'lines' => [
                    ['tone' => 'cmd', 'text' => '~ $ dply database install '.$engine],
                    ['tone' => 'muted', 'text' => 'Provisioning over SSH …'],
                    ['tone' => 'ok', 'text' => 'engine ready'],
                ],
                'features' => [
                    ['icon' => 'circle-stack', 'title' => __('One-click install'), 'body' => __('apt + systemctl over SSH with memory/disk preflight checks.')],
                    ['icon' => 'key', 'title' => __('Managed users & grants'), 'body' => __('Create databases and scoped users from the workspace.')],
                    ['icon' => 'arrow-uturn-left', 'title' => __('Backups & restore'), 'body' => __('Scheduled exports to your S3-style destination with one-click restore.')],
                ],
            ],
        };
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
