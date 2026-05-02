<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servers\AttachDatabaseEngineToServer;
use App\Actions\Servers\InstallDatabaseEngineOnServer;
use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InstallDatabaseEngineOnServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_runs_apt_steps_then_registers_postgres(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $shell = new InstallDbRecordingShell;

        $action = $this->app->make(InstallDatabaseEngineOnServer::class);
        $result = $action->execute($server, 'postgres17', '17', isDefault: true, shellFactory: fn () => $shell);

        $this->assertTrue($result['ok']);
        $this->assertSame('postgres17', $result['engine']);
        $this->assertNotEmpty($shell->execCalls);

        // Postgres install runs apt commands referencing postgresql-17.
        $combined = implode("\n", $shell->execCalls);
        $this->assertStringContainsString('postgresql-17', $combined);

        // Engine row was registered.
        $row = ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->where('engine', 'postgres17')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('17', $row->version);
        $this->assertTrue($row->is_default);
    }

    public function test_install_runs_apt_steps_for_mysql_84(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $shell = new InstallDbRecordingShell;

        $action = $this->app->make(InstallDatabaseEngineOnServer::class);
        $result = $action->execute($server, 'mysql84', '8.4', shellFactory: fn () => $shell);

        $this->assertTrue($result['ok']);
        $combined = implode("\n", $shell->execCalls);
        $this->assertStringContainsString('mysql-server', $combined);
    }

    public function test_install_returns_unrecognized_engine_with_ok_false(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);
        $shell = new InstallDbRecordingShell;

        $action = $this->app->make(InstallDatabaseEngineOnServer::class);
        $result = $action->execute($server, 'duckdb', null, shellFactory: fn () => $shell);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $shell->execCalls);
        $this->assertNull(ServerDatabaseEngine::query()->where('engine', 'duckdb')->first());
    }

    public function test_install_throws_when_server_not_ready(): void
    {
        $server = Server::factory()->create([
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server must be ready');

        $this->app->make(InstallDatabaseEngineOnServer::class)
            ->execute($server, 'postgres17', '17', shellFactory: fn () => new InstallDbRecordingShell);
    }

    public function test_install_rejects_blank_engine(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->app->make(InstallDatabaseEngineOnServer::class)
            ->execute($server, '   ', null, shellFactory: fn () => new InstallDbRecordingShell);
    }

    public function test_add_engine_command_with_install_flag_falls_back_when_engine_unknown(): void
    {
        $server = Server::factory()->ready()->create([
            'name' => 'edge-1',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);

        // Need a mock shell — but the command uses the real action which
        // would try to SSH. Bind a fake action that no-ops and returns
        // ok=false to exercise the fallback path.
        $this->app->bind(InstallDatabaseEngineOnServer::class, function () {
            return new class extends InstallDatabaseEngineOnServer {
                public function __construct() {}

                public function execute(
                    Server $server,
                    string $engine,
                    ?string $version = null,
                    bool $isDefault = false,
                    ?\Closure $shellFactory = null,
                ): array {
                    return ['ok' => false, 'engine' => $engine, 'output' => ''];
                }
            };
        });

        $exit = Artisan::call('dply:server:add-engine', [
            'server' => 'edge-1',
            'engine' => 'duckdb',
            '--install' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No install steps known', $output);
        $this->assertNotNull(ServerDatabaseEngine::query()->where('engine', 'duckdb')->first());
    }
}

class InstallDbRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $execCalls = [];

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
    }
}
