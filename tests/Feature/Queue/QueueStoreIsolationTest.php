<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\QueueStoreIsolationTest;

use App\Modules\Queue\Support\QueueStoreIsolation;

/**
 * The queue connection falls through to the primary `DB_*` when the
 * `DPLY_QUEUE_DB_*` overrides are unset, so "separate store" is the assumption
 * the rest of the module is written on and NOT the default state. These pin the
 * detection, because the failure mode is silence.
 */
function setConnections(array $queue, ?array $primary = null): void
{
    config(['database.default' => 'pgsql']);
    config(['database.connections.pgsql' => array_merge([
        'driver' => 'pgsql', 'host' => '10.0.0.3', 'port' => '5432', 'database' => 'dply',
    ], $primary ?? [])]);
    config(['database.connections.dply_queue' => array_merge([
        'driver' => 'pgsql', 'host' => '10.0.0.3', 'port' => '5432', 'database' => 'dply',
    ], $queue)]);
}

it('reports a shared store when the queue connection resolves to the primary database', function () {
    setConnections([]);

    expect(QueueStoreIsolation::isSeparate())->toBeFalse();
    expect(QueueStoreIsolation::summary())->toContain('SHARED');
    expect(QueueStoreIsolation::advice())->toContain('DPLY_QUEUE_DB_HOST');
});

it('is not fooled by the connection merely having a different name', function () {
    // This is the actual default: a connection called `dply_queue` pointed at
    // exactly the primary host, port and database. The name says separate; the
    // three values that matter say otherwise.
    setConnections(['database' => 'dply', 'host' => '10.0.0.3', 'port' => '5432']);

    expect(QueueStoreIsolation::isSeparate())->toBeFalse();
});

it('recognises a genuinely separate database', function (array $override) {
    setConnections($override);

    expect(QueueStoreIsolation::isSeparate())->toBeTrue();
    expect(QueueStoreIsolation::advice())->toBeNull();
})->with([
    'different database' => [['database' => 'dply_queue']],
    'different host' => [['host' => '10.0.0.9']],
    'different port' => [['port' => '6432']],
]);

it('resolves a dsn url rather than trusting the discrete keys', function () {
    // `url` overrides host/port/database for the driver. Comparing only the
    // discrete keys would call two different databases identical — or worse,
    // call one database two.
    setConnections(['url' => 'pgsql://u:p@10.0.0.9:5432/dply_queue']);

    expect(QueueStoreIsolation::isSeparate())->toBeTrue();
    expect(QueueStoreIsolation::summary())->toContain('10.0.0.9:5432/dply_queue');
});

it('sees through a dsn that points back at the primary database', function () {
    setConnections(['url' => 'pgsql://u:p@10.0.0.3:5432/dply']);

    expect(QueueStoreIsolation::isSeparate())->toBeFalse();
});

it('compares a dsn primary against a dsn queue connection', function () {
    setConnections(
        ['url' => 'pgsql://u:p@10.0.0.3:5432/dply'],
        ['url' => 'pgsql://u:p@10.0.0.3:5432/dply'],
    );

    expect(QueueStoreIsolation::isSeparate())->toBeFalse();
});
