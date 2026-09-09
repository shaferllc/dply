<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers\DatabaseCatalogTest;

use App\Support\Servers\DatabaseCatalog;

test('every enumerable engine has a list statement', function (string $engine) {
    expect(DatabaseCatalog::listStatementFor($engine))->not->toBeNull();
})->with(DatabaseCatalog::ENUMERABLE);

test('sqlite is not enumerable — a file has no catalog to query', function () {
    expect(DatabaseCatalog::supports('sqlite'))->toBeFalse()
        ->and(DatabaseCatalog::listStatementFor('sqlite'))->toBeNull();
});

test('version-suffixed engines resolve to their family', function () {
    expect(DatabaseCatalog::supports('postgres18'))->toBeTrue()
        ->and(DatabaseCatalog::supports('mysql84'))->toBeTrue()
        ->and(DatabaseCatalog::listStatementFor('mariadb11'))->toBe('SHOW DATABASES');
});

test('engine system databases are excluded', function () {
    expect(DatabaseCatalog::isSystemDatabase('postgres', 'template0'))->toBeTrue()
        ->and(DatabaseCatalog::isSystemDatabase('postgres', 'postgres'))->toBeTrue()
        ->and(DatabaseCatalog::isSystemDatabase('mysql', 'information_schema'))->toBeTrue()
        ->and(DatabaseCatalog::isSystemDatabase('mongodb', 'admin'))->toBeTrue()
        ->and(DatabaseCatalog::isSystemDatabase('clickhouse', 'system'))->toBeTrue()
        // Someone's actual data is not a system database.
        ->and(DatabaseCatalog::isSystemDatabase('postgres', 'databio'))->toBeFalse();
});

test('parsing keeps real names and drops system ones', function () {
    $raw = "databio\ntemplate0\ntemplate1\npostgres\norders_legacy\n";

    expect(DatabaseCatalog::parseNames('postgres', $raw))->toBe(['databio', 'orders_legacy']);
});

test('client chatter is never adopted as a database name', function () {
    // psql notices, mongosh banners and warnings all land on stdout alongside
    // the rows; adopting one would create a database called "Warning: ...".
    $raw = implode("\n", [
        'Warning: Using a password on the command line interface can be insecure.',
        'databio',
        'could not change directory to "/root": Permission denied',
        '+------------+',
        'orders_legacy',
        '',
    ]);

    expect(DatabaseCatalog::parseNames('mysql', $raw))->toBe(['databio', 'orders_legacy']);
});

test('duplicate lines collapse', function () {
    expect(DatabaseCatalog::parseNames('postgres', "app\napp\napp\n"))->toBe(['app']);
});

test('an empty listing yields no names rather than erroring', function () {
    expect(DatabaseCatalog::parseNames('postgres', ''))->toBe([]);
});
