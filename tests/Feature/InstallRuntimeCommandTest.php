<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servers\InstallRuntimeOnServer;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class InstallRuntimeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_installs_node_via_action(): void
    {
        $server = Server::factory()->ready()->create([
            'name' => 'edge-1',
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);

        $action = Mockery::mock(InstallRuntimeOnServer::class);
        $action->shouldReceive('execute')
            ->once()
            ->withArgs(function (Server $s, string $r, string $v) use ($server) {
                return $s->id === $server->id && $r === 'node' && $v === '22.7.0';
            })
            ->andReturn([
                'installed' => true,
                'runtime' => 'node',
                'version' => '22.7.0',
                'output' => 'mise install line ran',
            ]);
        $this->app->instance(InstallRuntimeOnServer::class, $action);

        $exit = Artisan::call('dply:install-runtime', [
            'server' => 'edge-1',
            'runtime' => 'node',
            'version' => '22.7.0',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Installed node@22.7.0 on edge-1', $output);
    }

    public function test_command_resolves_server_by_id(): void
    {
        $server = Server::factory()->ready()->create([
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);

        $action = Mockery::mock(InstallRuntimeOnServer::class);
        $action->shouldReceive('execute')->once()->andReturn([
            'installed' => true,
            'runtime' => 'python',
            'version' => '3.12',
            'output' => '',
        ]);
        $this->app->instance(InstallRuntimeOnServer::class, $action);

        $exit = Artisan::call('dply:install-runtime', [
            'server' => $server->id,
            'runtime' => 'python',
            'version' => '3.12',
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_command_fails_when_server_not_found(): void
    {
        $exit = Artisan::call('dply:install-runtime', [
            'server' => 'no-such-server',
            'runtime' => 'node',
            'version' => '22',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }

    public function test_command_warns_when_action_silently_skips(): void
    {
        $server = Server::factory()->ready()->create([
            'name' => 'edge-1',
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);

        $action = Mockery::mock(InstallRuntimeOnServer::class);
        $action->shouldReceive('execute')->once()->andReturn([
            'installed' => false,
            'runtime' => 'php',
            'version' => '8.4',
            'output' => '',
        ]);
        $this->app->instance(InstallRuntimeOnServer::class, $action);

        $exit = Artisan::call('dply:install-runtime', [
            'server' => 'edge-1',
            'runtime' => 'php',
            'version' => '8.4',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Skipped', $output);
    }

    public function test_command_emits_machine_readable_json_with_flag(): void
    {
        $server = Server::factory()->ready()->create([
            'name' => 'edge-1',
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);

        $action = Mockery::mock(InstallRuntimeOnServer::class);
        $action->shouldReceive('execute')->once()->andReturn([
            'installed' => true,
            'runtime' => 'ruby',
            'version' => '3.3.4',
            'output' => 'mise: installing ruby@3.3.4',
        ]);
        $this->app->instance(InstallRuntimeOnServer::class, $action);

        $exit = Artisan::call('dply:install-runtime', [
            'server' => 'edge-1',
            'runtime' => 'ruby',
            'version' => '3.3.4',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('ruby', $decoded['runtime']);
        $this->assertSame('3.3.4', $decoded['version']);
        $this->assertSame($server->id, $decoded['server_id']);
    }

    public function test_command_emits_json_error_when_action_throws(): void
    {
        $server = Server::factory()->ready()->create([
            'name' => 'edge-1',
            'ssh_user' => 'dply',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
        ]);

        $action = Mockery::mock(InstallRuntimeOnServer::class);
        $action->shouldReceive('execute')->once()->andThrow(new \RuntimeException('SSH closed'));
        $this->app->instance(InstallRuntimeOnServer::class, $action);

        $exit = Artisan::call('dply:install-runtime', [
            'server' => 'edge-1',
            'runtime' => 'node',
            'version' => '22',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $decoded = json_decode($output, true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('SSH closed', $decoded['error']);
    }
}
