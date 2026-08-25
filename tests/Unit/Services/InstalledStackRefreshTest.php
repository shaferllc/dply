<?php

declare(strict_types=1);

namespace Tests\Unit\Services\InstalledStackRefreshTest;

use App\Services\Servers\ServerInventoryProbeScript;
use App\Support\Servers\InstalledStack;

/**
 * meta.installed_stack was written once at the end of provisioning and never
 * again, so a server kept reporting the stack it was born with. A droplet whose
 * Postgres was swapped for SQLite by the low-memory fallback, then resized to
 * 2GB and given a real Postgres, still read database=sqlite3, low_mem_mode=true,
 * total_memory_mb=458.
 */
function refresh(array $meta): array
{
    $m = new \ReflectionMethod(ServerInventoryProbeScript::class, 'refreshInstalledStack');
    $m->setAccessible(true);

    return $m->invoke(app(ServerInventoryProbeScript::class), $meta)[InstalledStack::META_KEY];
}

test('a resized box with postgres stops reporting the sqlite fallback', function () {
    $stack = refresh([
        InstalledStack::META_KEY => [
            'database' => 'sqlite3',
            'low_mem_mode' => true,
            'total_memory_mb' => 458,
        ],
        'manage_postgres' => ['present' => true, 'version' => 'psql (PostgreSQL) 16.15 (Ubuntu 16.15-0ubuntu0.24.04.1)'],
        'manage_sqlite' => ['present' => true, 'version' => '3.45.1'],
        'manage_memory' => ['total_mb' => '1967', 'swap_mb' => '2047'],
    ]);

    expect($stack['database'])->toBe('postgres')
        ->and($stack['database_version'])->toBe('16.15')
        ->and($stack['low_mem_mode'])->toBeFalse()
        ->and($stack['total_memory_mb'])->toBe(1967);
});

test('a real engine outranks the sqlite substitute', function () {
    // SQLite is only ever the fallback; if a server engine is present it wins.
    $stack = refresh([
        'manage_mysql' => ['present' => true, 'version' => 'mysql  Ver 8.4.2'],
        'manage_sqlite' => ['present' => true, 'version' => '3.45.1'],
    ]);

    expect($stack['database'])->toBe('mysql');
});

test('mariadb is distinguished from mysql', function () {
    $stack = refresh([
        'manage_mysql' => ['present' => true, 'mariadb_present' => true, 'version' => 'mariadb  Ver 11.4.2'],
    ]);

    expect($stack['database'])->toBe('mariadb');
});

test('sqlite alone is still reported', function () {
    $stack = refresh(['manage_sqlite' => ['present' => true, 'version' => '3.45.1']]);

    expect($stack['database'])->toBe('sqlite3')
        ->and($stack['database_version'])->toBe('3.45.1');
});

test('a partial probe degrades the snapshot instead of destroying it', function () {
    // No database blocks probed at all: keep what we knew rather than blanking.
    $stack = refresh([
        InstalledStack::META_KEY => [
            'database' => 'postgres',
            'database_version' => '16.15',
            'webserver' => 'nginx',
            'total_memory_mb' => 1967,
        ],
    ]);

    expect($stack['database'])->toBe('postgres')
        ->and($stack['database_version'])->toBe('16.15')
        ->and($stack['webserver'])->toBe('nginx')
        ->and($stack['total_memory_mb'])->toBe(1967);
});

test('low memory is re-derived, not inherited', function () {
    $small = refresh(['manage_memory' => ['total_mb' => '458', 'swap_mb' => '0']]);
    expect($small['low_mem_mode'])->toBeTrue();

    $grown = refresh([
        InstalledStack::META_KEY => ['low_mem_mode' => true],
        'manage_memory' => ['total_mb' => '1967', 'swap_mb' => '2047'],
    ]);
    expect($grown['low_mem_mode'])->toBeFalse();
});

test('a box probed with no php-fpm reports none', function () {
    $stack = refresh(['manage_php_fpm' => ['versions' => []]]);

    expect($stack['php_version'])->toBe('none');
});
