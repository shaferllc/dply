<?php

declare(strict_types=1);

use App\Modules\Backups\Services\BackupFailureExplainer;

it('explains a stopped mysql engine instead of quoting the socket path', function () {
    $result = (new BackupFailureExplainer)->explain(
        "Dump command failed: mysqldump: Got error: 2002: Can't connect to local MySQL server through socket '/var/run/mysqld/mysqld.sock' (2) when trying to connect",
        'mysql84',
        'dply-app',
    );

    // The whole point: an operator should learn the engine is down, not read an
    // error code and a socket path.
    expect($result['summary'])->toContain('MySQL is not running')
        ->toContain('dply-app');
    expect($result['action'])->toContain('Start the service');
    // The original is kept for anything the explanation loses.
    expect($result['raw'])->toContain('2002');
});

it('separates a refused remote connection from a stopped local engine', function () {
    $explainer = new BackupFailureExplainer;

    $local = $explainer->explain("mysqldump: Got error: 2002: Can't connect to local MySQL server through socket", 'mysql', 'box');
    $remote = $explainer->explain("mysqldump: Got error: 2003: Can't connect to MySQL server on 'db.internal' (110)", 'mysql', 'box');

    // Both are "cannot connect" but need completely different fixes.
    expect($local['action'])->toContain('Start the service');
    expect($remote['action'])->toContain('host and port');
    expect($local['summary'])->not->toBe($remote['summary']);
});

it('names the engine it is talking about', function (?string $engine, string $expected) {
    $result = (new BackupFailureExplainer)->explain('could not connect to server: Connection refused', $engine);

    expect($result['summary'])->toContain($expected);
})->with([
    'postgres' => ['postgres', 'PostgreSQL'],
    'mariadb' => ['mariadb', 'MariaDB'],
    'unknown' => [null, 'The database engine'],
]);

it('recognises the failures an operator can actually act on', function (string $raw, string $needle) {
    expect((new BackupFailureExplainer)->explain($raw, 'mysql')['summary'])->toContain($needle);
})->with([
    'bad credentials' => ["mysqldump: Got error: 1045: Access denied for user 'app'@'localhost'", 'credentials were rejected'],
    'missing database' => ['mysqldump: Got error: 1049: Unknown database', 'no longer exists'],
    'disk full' => ['gzip: write error: No space left on device', 'out of disk space'],
    'missing tool' => ['bash: mysqldump: command not found', 'not installed'],
    'timeout' => ['Operation timed out after 3600 seconds', 'timed out'],
    'empty dump' => ['Backup produced an empty file.', 'produced no data'],
]);

it('hands back an unfamiliar error untouched rather than guessing', function () {
    $raw = 'something nobody has seen before happened';
    $result = (new BackupFailureExplainer)->explain($raw, 'mysql');

    // A confident wrong diagnosis is worse than the raw text.
    expect($result['summary'])->toBe($raw);
    expect($result['action'])->toBeNull();
});

it('copes with a failure that reported nothing at all', function () {
    $result = (new BackupFailureExplainer)->explain(null);

    expect($result['summary'])->toContain('without reporting a reason');
    expect($result['raw'])->toBe('');
});
