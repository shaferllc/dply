<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerDatabaseProvisionerSqliteRelocateTest;
use Mockery;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerDatabaseRemoteExec;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function readyServer(): Server
{
    return Server::factory()->ready()->create([
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----",
    ]);
}
test('idempotent when old path equals new path', function () {
    $server = readyServer();
    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'reports',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => '/var/lib/dply/sqlite/reports.db',
    ]);

    $exec = Mockery::mock(ServerDatabaseRemoteExec::class);

    // No SSH should be required when paths match.
    $exec->shouldNotReceive('shellRunWithExit');

    $provisioner = new ServerDatabaseProvisioner($exec);
    $out = $provisioner->relocateSqliteFile($db, '/var/lib/dply/sqlite/reports.db');

    $this->assertStringContainsString('unchanged', $out);
});
test('runs mv when paths differ', function () {
    $server = readyServer();
    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'reports',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => '/var/lib/dply/sqlite/reports.db',
    ]);

    $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
    $exec->shouldReceive('shellRunWithExit')
        ->once()
        ->withArgs(function ($srv, string $cmd, $timeout): bool {
            return str_contains($cmd, 'mv ')
                && str_contains($cmd, '/var/lib/dply/sqlite/reports.db')
                && str_contains($cmd, '/var/lib/dply/sqlite/archive/reports.db');
        })
        ->andReturn(['[dply] moved', 0]);

    $provisioner = new ServerDatabaseProvisioner($exec);
    $out = $provisioner->relocateSqliteFile($db, '/var/lib/dply/sqlite/archive/reports.db');

    $this->assertStringContainsString('moved', $out);
});
test('throws when destination escapes the sqlite root', function () {
    $server = readyServer();
    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'reports',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => '/var/lib/dply/sqlite/reports.db',
    ]);

    $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
    $exec->shouldNotReceive('shellRunWithExit');
    $provisioner = new ServerDatabaseProvisioner($exec);

    $this->expectException(\InvalidArgumentException::class);
    $provisioner->relocateSqliteFile($db, '/etc/shadow');
});
test('normalizes dot dot segments to block traversal', function () {
    $server = readyServer();
    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'reports',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => '/var/lib/dply/sqlite/reports.db',
    ]);

    $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
    $exec->shouldNotReceive('shellRunWithExit');
    $provisioner = new ServerDatabaseProvisioner($exec);

    $this->expectException(\InvalidArgumentException::class);
    $provisioner->relocateSqliteFile($db, '/var/lib/dply/sqlite/../../etc/shadow');
});
test('throws when called on non sqlite engine', function () {
    $server = readyServer();
    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'reports',
        'engine' => 'mysql',
        'username' => 'r',
        'password' => 'p',
        'host' => '127.0.0.1',
    ]);

    $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
    $provisioner = new ServerDatabaseProvisioner($exec);

    $this->expectException(\InvalidArgumentException::class);
    $provisioner->relocateSqliteFile($db, '/var/lib/dply/sqlite/whatever.db');
});
test('propagates remote failure as runtime exception', function () {
    $server = readyServer();
    $db = ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'reports',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => '/var/lib/dply/sqlite/reports.db',
    ]);

    $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
    $exec->shouldReceive('shellRunWithExit')
        ->once()
        ->andReturn(['mv: cannot stat …', 1]);

    $provisioner = new ServerDatabaseProvisioner($exec);

    $this->expectException(\RuntimeException::class);
    $provisioner->relocateSqliteFile($db, '/var/lib/dply/sqlite/archive/reports.db');
});
