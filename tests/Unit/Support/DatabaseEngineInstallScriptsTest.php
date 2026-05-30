<?php

use App\Support\Servers\DatabaseEngineInstallScripts;

test('supported engines include mongodb and clickhouse', function () {
    expect(DatabaseEngineInstallScripts::supportedEngines())
        ->toContain('mongodb', 'clickhouse');
});

test('default ports for new engines', function () {
    expect(DatabaseEngineInstallScripts::defaultPortFor('mongodb'))->toBe(27017)
        ->and(DatabaseEngineInstallScripts::defaultPortFor('clickhouse'))->toBe(8123);
});
