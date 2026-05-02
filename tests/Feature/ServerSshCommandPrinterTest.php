<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ServerSshCommandPrinterTest extends TestCase
{
    use RefreshDatabase;

    public function test_prints_ssh_command_with_default_user(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
        ]);

        $exit = Artisan::call('dply:server:ssh', ['server' => $server->id]);
        $output = trim(Artisan::output());

        $this->assertSame(0, $exit);
        $user = config('server_provision.deploy_ssh_user');
        $this->assertSame("ssh {$user}@203.0.113.10", $output);
    }

    public function test_prints_with_root_user_when_root_flag(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.10',
            'ssh_port' => 22,
        ]);

        Artisan::call('dply:server:ssh', [
            'server' => $server->id,
            '--root' => true,
        ]);
        $output = trim(Artisan::output());

        $this->assertSame('ssh root@203.0.113.10', $output);
    }

    public function test_includes_port_when_non_default(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.10',
            'ssh_port' => 2222,
        ]);

        Artisan::call('dply:server:ssh', ['server' => $server->id]);
        $output = trim(Artisan::output());

        $this->assertStringContainsString('-p 2222', $output);
        $this->assertStringContainsString('@203.0.113.10', $output);
    }

    public function test_json_output_includes_components(): void
    {
        $server = Server::factory()->create([
            'ip_address' => '203.0.113.10',
            'ssh_port' => 2222,
        ]);

        Artisan::call('dply:server:ssh', [
            'server' => $server->id,
            '--root' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('203.0.113.10', $decoded['host']);
        $this->assertSame(2222, $decoded['port']);
        $this->assertSame('root', $decoded['user']);
        $this->assertStringContainsString('-p 2222', $decoded['command']);
    }

    public function test_fails_when_server_has_no_ip(): void
    {
        $server = Server::factory()->create(['ip_address' => null]);

        $exit = Artisan::call('dply:server:ssh', ['server' => $server->id]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no IP address', $output);
    }

    public function test_fails_when_server_not_found(): void
    {
        $exit = Artisan::call('dply:server:ssh', ['server' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }
}
