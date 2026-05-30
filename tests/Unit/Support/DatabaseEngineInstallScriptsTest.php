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

test('clickhouse install script defers postinst start and verifies the daemon', function () {
    $script = DatabaseEngineInstallScripts::installScript('clickhouse');

    expect($script)->toContain('policy-rc.d')
        ->and($script)->toContain('TimeoutStartSec=300')
        ->and($script)->toContain('systemctl is-active --quiet clickhouse-server');
});
