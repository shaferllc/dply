<?php

use App\Support\Servers\DatabaseEngineAvailability;

test('mysql postgres and sqlite are always available', function () {
    expect(DatabaseEngineAvailability::isAvailable('mysql'))->toBeTrue()
        ->and(DatabaseEngineAvailability::isAvailable('postgres'))->toBeTrue()
        ->and(DatabaseEngineAvailability::isAvailable('sqlite'))->toBeTrue()
        ->and(DatabaseEngineAvailability::isComingSoon('mysql'))->toBeFalse();
});

test('gated database engines default to coming soon', function () {
    foreach (DatabaseEngineAvailability::GATED_ENGINES as $engine) {
        expect(DatabaseEngineAvailability::isComingSoon($engine))->toBeTrue()
            ->and(DatabaseEngineAvailability::isAvailable($engine))->toBeFalse()
            ->and(DatabaseEngineAvailability::flagFor($engine))->toBe("database.{$engine}");
    }
});

test('provision option ids map to workspace engine families', function () {
    expect(DatabaseEngineAvailability::familyForProvisionOption('mariadb114'))->toBe('mariadb')
        ->and(DatabaseEngineAvailability::familyForProvisionOption('mariadb11'))->toBe('mariadb')
        ->and(DatabaseEngineAvailability::familyForProvisionOption('mongodb'))->toBe('mongodb')
        ->and(DatabaseEngineAvailability::familyForProvisionOption('clickhouse'))->toBe('clickhouse')
        ->and(DatabaseEngineAvailability::familyForProvisionOption('mysql84'))->toBeNull();
});
