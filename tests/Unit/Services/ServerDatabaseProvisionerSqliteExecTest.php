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

class ServerDatabaseProvisionerSqliteExecTest extends TestCase
{
    use RefreshDatabase;

    private function readyServer(): Server
    {
        return Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----",
        ]);
    }

    private function sqliteDatabase(Server $server, string $host = '/var/lib/dply/sqlite/store.db'): ServerDatabase
    {
        return ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'store',
            'engine' => 'sqlite',
            'username' => '',
            'password' => '',
            'host' => $host,
        ]);
    }

    public function test_runs_sql_via_sqlite_exec_and_returns_trimmed_output(): void
    {
        $server = $this->readyServer();
        $db = $this->sqliteDatabase($server);

        $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
        $exec->shouldReceive('sqliteExec')
            ->once()
            ->withArgs(function ($srv, string $path, string $sql, $timeout): bool {
                return $path === '/var/lib/dply/sqlite/store.db'
                    && trim($sql) === 'SELECT 1;';
            })
            ->andReturn(["1\n", 0]);

        $provisioner = new ServerDatabaseProvisioner($exec);
        $output = $provisioner->executeSqliteSql($db, "  SELECT 1;\n");

        $this->assertSame('1', $output);
    }

    public function test_rejects_empty_sql(): void
    {
        $server = $this->readyServer();
        $db = $this->sqliteDatabase($server);

        $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
        $exec->shouldNotReceive('sqliteExec');

        $provisioner = new ServerDatabaseProvisioner($exec);

        $this->expectException(\InvalidArgumentException::class);
        $provisioner->executeSqliteSql($db, '   ');
    }

    public function test_rejects_oversized_sql_against_import_max_bytes(): void
    {
        config()->set('server_database.import_max_bytes', 32);

        $server = $this->readyServer();
        $db = $this->sqliteDatabase($server);

        $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
        $exec->shouldNotReceive('sqliteExec');

        $provisioner = new ServerDatabaseProvisioner($exec);

        $this->expectException(\InvalidArgumentException::class);
        $provisioner->executeSqliteSql($db, str_repeat('A', 64));
    }

    public function test_throws_on_non_sqlite_engine(): void
    {
        $server = $this->readyServer();
        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => 'store',
            'engine' => 'mysql',
            'username' => 'r',
            'password' => 'p',
            'host' => '127.0.0.1',
        ]);

        $exec = Mockery::mock(ServerDatabaseRemoteExec::class);
        $exec->shouldNotReceive('sqliteExec');

        $provisioner = new ServerDatabaseProvisioner($exec);

        $this->expectException(\InvalidArgumentException::class);
        $provisioner->executeSqliteSql($db, 'SELECT 1;');
    }
}
