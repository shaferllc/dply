<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerDatabaseRemoteExec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ServerDatabaseProvisionerSqliteRelocateTest extends TestCase
{
    use RefreshDatabase;

    private function readyServer(): Server
    {
        return Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----",
        ]);
    }

    public function test_idempotent_when_old_path_equals_new_path(): void
    {
        $server = $this->readyServer();
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
    }

    public function test_runs_mv_when_paths_differ(): void
    {
        $server = $this->readyServer();
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
    }

    public function test_throws_when_destination_escapes_the_sqlite_root(): void
    {
        $server = $this->readyServer();
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
    }

    public function test_normalizes_dot_dot_segments_to_block_traversal(): void
    {
        $server = $this->readyServer();
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
    }

    public function test_throws_when_called_on_non_sqlite_engine(): void
    {
        $server = $this->readyServer();
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
    }

    public function test_propagates_remote_failure_as_runtime_exception(): void
    {
        $server = $this->readyServer();
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
    }
}
