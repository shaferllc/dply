<?php

declare(strict_types=1);

/**
 * Per-engine minimum resource thresholds for the install preflight. Override any value via
 * config/cache or .env to tune for tight boxes (e.g. 256 MB droplets where the operator knows
 * what they're doing). The keys are engine names — keep them aligned with the rest of the app:
 *   - cache_services: redis / valkey / keydb / dragonfly / memcached
 *   - database_engines: mysql / mariadb / postgres / sqlite
 *
 * Both maps are keyed by engine + a `_default` fallback so a new engine variant doesn't
 * silently bypass the gate.
 */
return [

    'cache_services' => [
        '_default' => ['min_ram_mb' => 128, 'min_disk_mb' => 256],
        'memcached' => ['min_ram_mb' => 64, 'min_disk_mb' => 64],
        'redis' => ['min_ram_mb' => 128, 'min_disk_mb' => 256],
        'valkey' => ['min_ram_mb' => 128, 'min_disk_mb' => 256],
        'keydb' => ['min_ram_mb' => 128, 'min_disk_mb' => 256],
        'dragonfly' => ['min_ram_mb' => 256, 'min_disk_mb' => 256],
    ],

    'database_engines' => [
        '_default' => ['min_ram_mb' => 256, 'min_disk_mb' => 1024],
        'sqlite' => ['min_ram_mb' => 0, 'min_disk_mb' => 16],
        'sqlite3' => ['min_ram_mb' => 0, 'min_disk_mb' => 16],
        'postgres' => ['min_ram_mb' => 256, 'min_disk_mb' => 1024],
        'postgres15' => ['min_ram_mb' => 256, 'min_disk_mb' => 1024],
        'postgres16' => ['min_ram_mb' => 256, 'min_disk_mb' => 1024],
        'postgres17' => ['min_ram_mb' => 256, 'min_disk_mb' => 1024],
        'mysql' => ['min_ram_mb' => 512, 'min_disk_mb' => 1024],
        'mysql80' => ['min_ram_mb' => 512, 'min_disk_mb' => 1024],
        'mysql84' => ['min_ram_mb' => 512, 'min_disk_mb' => 1024],
        'mariadb' => ['min_ram_mb' => 384, 'min_disk_mb' => 1024],
        'mariadb1011' => ['min_ram_mb' => 384, 'min_disk_mb' => 1024],
        'mariadb11' => ['min_ram_mb' => 384, 'min_disk_mb' => 1024],
        'mariadb114' => ['min_ram_mb' => 384, 'min_disk_mb' => 1024],
        'mongodb' => ['min_ram_mb' => 512, 'min_disk_mb' => 1024],
        'clickhouse' => ['min_ram_mb' => 1024, 'min_disk_mb' => 2048],
    ],
];
