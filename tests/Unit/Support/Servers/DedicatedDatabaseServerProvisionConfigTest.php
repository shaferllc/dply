<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Servers;

use App\Models\Server;
use App\Support\Servers\DedicatedDatabaseServerProvisionConfig;
use Illuminate\Support\Facades\Crypt;

test('engine family normalizes wizard database ids', function () {
    expect(DedicatedDatabaseServerProvisionConfig::engineFamily('postgres17'))->toBe('postgres')
        ->and(DedicatedDatabaseServerProvisionConfig::engineFamily('mysql84'))->toBe('mysql')
        ->and(DedicatedDatabaseServerProvisionConfig::engineFamily('mariadb1011'))->toBe('mariadb')
        ->and(DedicatedDatabaseServerProvisionConfig::engineFamily('sqlite3'))->toBe('sqlite');
});

test('fromServer reads database_server meta', function () {
    $config = DedicatedDatabaseServerProvisionConfig::fromServer(null, 'postgres17');
    $config = DedicatedDatabaseServerProvisionConfig::fromServer(
        new Server([
            'meta' => [
                'database_server' => [
                    'remote_access' => true,
                    'allowed_from' => '10.0.0.0/8',
                    'database_name' => 'analytics',
                    'username' => 'dply_app',
                    'password_encrypted' => Crypt::encryptString('RemoteDb-Password12'),
                ],
            ],
        ]),
        'postgres17',
    );

    expect($config->remoteAccess)->toBeTrue()
        ->and($config->allowedFrom)->toBe('10.0.0.0/8')
        ->and($config->databaseName)->toBe('analytics')
        ->and($config->username)->toBe('dply_app')
        ->and($config->password)->toBe('RemoteDb-Password12');
});

test('bootstrap lines include postgres user and database creation', function () {
    $config = new DedicatedDatabaseServerProvisionConfig(
        'postgres17',
        true,
        '10.0.0.0/8',
        'app',
        'dply_app',
        'RemoteDb-Password12',
    );

    $lines = $config->bootstrapLines();
    $joined = implode("\n", $lines);

    expect($joined)->toContain('dply_write_file')
        ->and($joined)->toContain('host all all 10.0.0.0/8 scram-sha-256')
        ->and($joined)->toContain('CREATE DATABASE app')
        ->and($joined)->toContain('dply_app');
});
