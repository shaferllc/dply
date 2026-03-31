<?php

namespace Tests\Feature\TaskRunner;

use App\Models\Server;
use App\Modules\TaskRunner\Connection;
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
}
