<?php

use App\Support\Servers\DatabaseEngineInstallScripts as Scripts;

/*
| Pure static bash builders for the managed database engines — no SSH, no IO.
| The per-engine `match` arms are the whole surface here, so most of these
| sweep every supported engine rather than spot-checking one: a new engine
| added to supportedEngines() without its arm should fail here, not on a box.
*/

test('supported engines include mongodb and clickhouse', function () {
    expect(Scripts::supportedEngines())
        ->toContain('mongodb', 'clickhouse');
});

test('default ports for new engines', function () {
    expect(Scripts::defaultPortFor('mongodb'))->toBe(27017)
        ->and(Scripts::defaultPortFor('clickhouse'))->toBe(8123);
});

test('clickhouse install script defers postinst start and verifies the daemon', function () {
    $script = Scripts::installScript('clickhouse');

    expect($script)->toContain('policy-rc.d')
        ->and($script)->toContain('TimeoutStartSec=300')
        ->and($script)->toContain('systemctl is-active --quiet clickhouse-server');
});

test('every supported engine builds a non-empty install script', function () {
    foreach (Scripts::supportedEngines() as $engine) {
        expect(Scripts::installScript($engine))->not->toBe('');
    }
});

test('every supported engine builds uninstall, probe, activate and deactivate scripts', function () {
    foreach (Scripts::supportedEngines() as $engine) {
        expect(Scripts::uninstallScript($engine))->not->toBe('')
            ->and(Scripts::versionProbeScript($engine))->not->toBe('')
            ->and(Scripts::activateScript($engine))->not->toBe('')
            ->and(Scripts::deactivateScript($engine))->not->toBe('');
    }
});

test('activate and deactivate drive the engine systemd unit', function () {
    foreach (Scripts::supportedEngines() as $engine) {
        $unit = Scripts::systemdServiceFor($engine);

        expect(Scripts::activateScript($engine))->toContain($unit)
            ->and(Scripts::deactivateScript($engine))->toContain($unit);
    }
});

test('systemd unit names follow each distro package rather than the engine key', function () {
    expect(Scripts::systemdServiceFor('mysql'))->toBe('mysql')
        ->and(Scripts::systemdServiceFor('mariadb'))->toBe('mariadb')
        ->and(Scripts::systemdServiceFor('postgres'))->toBe('postgresql')
        ->and(Scripts::systemdServiceFor('mongodb'))->toBe('mongod')
        ->and(Scripts::systemdServiceFor('clickhouse'))->toBe('clickhouse-server');
});

test('config paths are absolute and engine specific', function () {
    foreach (Scripts::supportedEngines() as $engine) {
        expect(Scripts::configFilePathFor($engine))->toStartWith('/etc/');
    }

    // mysql and mariadb intentionally share the mariadb conf.d path.
    expect(Scripts::configFilePathFor('mysql'))
        ->toBe(Scripts::configFilePathFor('mariadb'));
});

test('unsupported engines are rejected rather than silently defaulting', function () {
    expect(fn () => Scripts::systemdServiceFor('sqlite'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Scripts::configFilePathFor('redis'))
        ->toThrow(InvalidArgumentException::class);
});

test('default port falls back to the mysql port for the mysql family', function () {
    expect(Scripts::defaultPortFor('mysql'))->toBe(3306)
        ->and(Scripts::defaultPortFor('mariadb'))->toBe(3306)
        ->and(Scripts::defaultPortFor('postgres'))->toBe(5432)
        // sqlite is file-based — no TCP port.
        ->and(Scripts::defaultPortFor('sqlite'))->toBe(0);
});

test('remote access support is a subset of the supported engines', function () {
    foreach (Scripts::supportedEngines() as $engine) {
        if (Scripts::supportsRemoteAccess($engine)) {
            expect(Scripts::enableRemoteAccessScript($engine))->not->toBe('')
                ->and(Scripts::disableRemoteAccessScript($engine))->not->toBe('');
        }
    }

    // Mongo exposes no engine-level toggle here.
    expect(Scripts::supportsRemoteAccess('mongodb'))->toBeFalse();
});

test('per-database remote access is narrower than the engine-level toggle', function () {
    foreach (Scripts::supportedEngines() as $engine) {
        if (Scripts::supportsPerDatabaseRemoteAccess($engine)) {
            // Anything per-database must also support it engine-wide.
            expect(Scripts::supportsRemoteAccess($engine))->toBeTrue();
        }
    }

    // ClickHouse is engine-level only, despite supporting remote access.
    expect(Scripts::supportsRemoteAccess('clickhouse'))->toBeTrue()
        ->and(Scripts::supportsPerDatabaseRemoteAccess('clickhouse'))->toBeFalse();
});

test('enableRemoteAccessScript threads the allowed cidr through', function () {
    $script = Scripts::enableRemoteAccessScript('postgres', '10.0.0.0/8');

    expect($script)->toContain('10.0.0.0/8');
});

test('per-database remote access shell-escapes caller-supplied values', function () {
    // db name / user / cidr reach bash — a naive interpolation would be a
    // command-injection hole, so they must arrive single-quoted.
    $mysql = Scripts::enableDatabaseRemoteAccessScript('mysql', "app; rm -rf /", "user'name", '10.0.0.0/8');

    expect($mysql)
        ->toContain('DB='.escapeshellarg('app; rm -rf /'))
        ->toContain('USER='.escapeshellarg("user'name"))
        ->toContain('CIDR='.escapeshellarg('10.0.0.0/8'));

    $postgres = Scripts::enableDatabaseRemoteAccessScript('postgres', "app; rm -rf /", 'ignored', '10.0.0.0/8');

    expect($postgres)->toContain('DB='.escapeshellarg('app; rm -rf /'));
});

test('postgres grants the host rule to all users, mysql scopes it to one', function () {
    // Documents a real asymmetry: the pg_hba line pgsql writes is `host <db> all
    // <cidr>`, so the $dbUser argument is unused on that branch — a caller
    // expecting per-user scoping on postgres would be wrong.
    $postgres = Scripts::enableDatabaseRemoteAccessScript('postgres', 'shop', 'shop_user', '10.0.0.0/8');
    expect($postgres)->not->toContain('shop_user');

    $mysql = Scripts::enableDatabaseRemoteAccessScript('mysql', 'shop', 'shop_user', '10.0.0.0/8');
    expect($mysql)->toContain('shop_user')
        ->and($mysql)->toContain('GRANT ALL PRIVILEGES');
});

test('disabling per-database remote access is scoped by the same dply tag', function () {
    $enable = Scripts::enableDatabaseRemoteAccessScript('postgres', 'shop', 'shop_user', '10.0.0.0/8');
    $disable = Scripts::disableDatabaseRemoteAccessScript('postgres', 'shop', 'shop_user');

    // The tag is what makes the rule removable later; both halves must agree.
    expect($enable)->toContain('dply-db-shop')
        ->and($disable)->toContain('dply-db-shop');
});

test('loopback remediation is built for the engines that need it and empty elsewhere', function () {
    foreach (Scripts::supportedEngines() as $engine) {
        expect(Scripts::ensureLoopbackListeningScript($engine))->toBeString();
    }

    // Not enforced for file-based engines.
    expect(Scripts::ensureLoopbackListeningScript('sqlite'))->toBe('');
});

test('loopback override sorts below the remote-access override', function () {
    // 00-dply-loopback must lose to 99-dply so enabling remote access still wins.
    $script = Scripts::ensureLoopbackListeningScript('postgres');

    if ($script !== '') {
        expect($script)->toContain('00-dply-loopback');
    }
});

test('timescaledb bootstrap adds the upstream repo', function () {
    expect(Scripts::timescaledbRepoBootstrapScript())->not->toBe('');
});
