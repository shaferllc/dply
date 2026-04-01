<?php

namespace Tests\Unit;

use App\Models\Server;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_ready_returns_true_when_status_ready(): void
    {
        $server = Server::factory()->ready()->create();

        $this->assertTrue($server->isReady());
    }

    public function test_is_ready_returns_false_when_status_pending(): void
    {
        $server = Server::factory()->pending()->create();

        $this->assertFalse($server->isReady());
    }

    public function test_get_ssh_connection_string_formats_correctly(): void
    {
        $server = Server::factory()->create([
            'ssh_user' => 'deploy',
            'ip_address' => '10.0.0.1',
        ]);

        $this->assertSame('deploy@10.0.0.1', $server->getSshConnectionString());
    }

    public function test_get_ssh_connection_string_uses_placeholder_when_no_ip(): void
    {
        $server = Server::factory()->create([
            'ssh_user' => 'root',
            'ip_address' => null,
        ]);

        $this->assertSame('root@0.0.0.0', $server->getSshConnectionString());
    }

    public function test_servers_table_has_dual_key_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('servers', 'ssh_operational_private_key'));
        $this->assertTrue(Schema::hasColumn('servers', 'ssh_recovery_private_key'));
    }
}
