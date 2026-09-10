<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MariadbVersionParsingTest;

use App\Services\Servers\ServerInventoryProbeScript;

/**
 * MariaDB clients lead their banner with their own PROTOCOL version, not the
 * server's. Taking the first number in the line reported a correctly
 * provisioned MariaDB 11.4 server as "15.1", and the requested-vs-installed
 * banner then accused the provisioner of installing the wrong series.
 */
function parseVersion(string $raw): ?string
{
    $method = new \ReflectionMethod(ServerInventoryProbeScript::class, 'databaseVersionNumber');
    $method->setAccessible(true);

    return $method->invoke(null, $raw);
}

test('the mariadb client protocol version is never mistaken for the server version', function (string $banner, string $expected) {
    expect(parseVersion($banner))->toBe($expected);
})->with([
    // The exact shape that produced the bug report.
    'mariadb 11.4 via the mysql compat shim' => [
        'mysql  Ver 15.1 Distrib 11.4.8-MariaDB, for debian-linux-gnu (x86_64) using EditLine wrapper',
        '11.4.8',
    ],
    'mariadb 10.11 distro build' => [
        'mysql  Ver 15.1 Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64) using EditLine wrapper',
        '10.11.14',
    ],
    // MariaDB 11.4+ ships a second shape with no Distrib at all.
    'mariadb 11.4 native banner' => [
        'mariadb from 11.4.8-MariaDB, client 15.2 for debian-linux-gnu (x86_64) using EditLine wrapper',
        '11.4.8',
    ],
]);

/** The fallback must stay correct for the engines that never had this problem. */
test('mysql and postgres banners still parse', function (string $banner, string $expected) {
    expect(parseVersion($banner))->toBe($expected);
})->with([
    'mysql 8.0' => ['mysql  Ver 8.0.39 for Linux on x86_64 (MySQL Community Server - GPL)', '8.0.39'],
    'mysql 8.4' => ['mysql  Ver 8.4.2 for Linux on x86_64 (MySQL Community Server - GPL)', '8.4.2'],
    'postgres 16' => ['psql (PostgreSQL) 16.4 (Ubuntu 16.4-1)', '16.4'],
]);

test('an unparseable banner yields null rather than a wrong number', function () {
    expect(parseVersion(''))->toBeNull()
        ->and(parseVersion('command not found'))->toBeNull();
});
