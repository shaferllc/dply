<?php

namespace Tests\Feature\TaskRunner;

use App\Models\Server;
use App\Modules\TaskRunner\Connection;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerTaskRunnerConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function validPrivateKey(): string
    {
        $path = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');

        return file_get_contents($path);
    }

    public function test_connection_as_user_uses_server_ssh_fields(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.10',
            'ssh_port' => 2222,
            'ssh_user' => 'deploy',
            'ssh_private_key' => $this->validPrivateKey(),
        ]);

        $connection = $server->connectionAsUser();

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('203.0.113.10', $connection->host);
        $this->assertSame(2222, $connection->port);
        $this->assertSame('deploy', $connection->username);
        $this->assertSame('/home/deploy/.dply-task-runner', $connection->scriptPath);
    }

    public function test_connection_as_root_uses_root_script_path(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.20',
            'ssh_port' => 22,
            'ssh_user' => 'deploy',
            'ssh_private_key' => $this->validPrivateKey(),
        ]);

        $connection = $server->connectionAsRoot();

        $this->assertSame('root', $connection->username);
        $this->assertSame('/root/.dply-task-runner', $connection->scriptPath);
    }

    public function test_connection_as_user_requires_ssh_user(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.1',
            'ssh_user' => '   ',
            'ssh_private_key' => $this->validPrivateKey(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no SSH user');

        $server->connectionAsUser();
    }

    public function test_connection_as_operational_user_prefers_operational_private_key(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.30',
            'ssh_port' => 2222,
            'ssh_user' => 'deploy',
            'ssh_private_key' => 'legacy-key',
            'ssh_operational_private_key' => $this->validPrivateKey(),
        ]);

        $connection = $server->connectionAsOperationalUser();

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('deploy', $connection->username);
        $this->assertSame($this->validPrivateKey(), $connection->privateKey);
        $this->assertSame('/home/deploy/.dply-task-runner', $connection->scriptPath);
    }

    public function test_connection_as_recovery_root_prefers_recovery_private_key(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.40',
            'ssh_port' => 22,
            'ssh_user' => 'deploy',
            'ssh_private_key' => 'legacy-key',
            'ssh_recovery_private_key' => $this->validPrivateKey(),
        ]);

        $connection = $server->connectionAsRecoveryRoot();

        $this->assertSame('root', $connection->username);
        $this->assertSame($this->validPrivateKey(), $connection->privateKey);
        $this->assertSame('/root/.dply-task-runner', $connection->scriptPath);
    }

    public function test_fake_cloud_server_ssh_user_dply_uses_default_home_script_path(): void
    {
        config(['server_provision_fake.provider_id_sentinel' => 'fake-local-test']);

        $server = Server::factory()->create([
            'ip_address' => '127.0.0.1',
            'ssh_port' => 2222,
            'ssh_user' => 'dply',
            'ssh_private_key' => $this->validPrivateKey(),
            'provider_id' => 'fake-local-test',
        ]);

        $this->assertTrue(FakeCloudProvision::isFakeServer($server));

        $connection = $server->connectionAsUser();

        $this->assertSame('/home/dply/.dply-task-runner', $connection->scriptPath);
    }

    public function test_connection_helpers_fall_back_to_legacy_private_key_during_rollout(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.50',
            'ssh_port' => 22,
            'ssh_user' => 'deploy',
            'ssh_private_key' => $this->validPrivateKey(),
            'ssh_operational_private_key' => null,
            'ssh_recovery_private_key' => null,
        ]);

        $operational = $server->connectionAsOperationalUser();
        $recovery = $server->connectionAsRecoveryRoot();

        $this->assertSame($this->validPrivateKey(), $operational->privateKey);
        $this->assertSame($this->validPrivateKey(), $recovery->privateKey);
    }
}
