<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\Sites\Clone\RepositoryTreeCopier;
use App\Services\SshConnection;
use Mockery;
use Tests\TestCase;

class RepositoryTreeCopierTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_same_server_copy_uses_rsync_via_ssh_runner(): void
    {
        $capturedExec = null;

        $server = new Server([
            'id' => '00000000-0000-0000-0000-000000000001',
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
            'ip_address' => '10.0.0.1',
        ]);
        $server->syncOriginal();

        $runner = Mockery::mock(ServerSshConnectionRunner::class);
        $runner->shouldReceive('run')
            ->once()
            ->withArgs(function (Server $s, callable $cb): bool {
                return true;
            })
            ->andReturnUsing(function (Server $s, callable $cb) use (&$capturedExec): void {
                $ssh = Mockery::mock(SshConnection::class);
                $ssh->shouldReceive('effectiveUsername')->andReturn('root');
                $ssh->shouldReceive('exec')
                    ->once()
                    ->withArgs(function (string $cmd, int $timeout) use (&$capturedExec): bool {
                        $capturedExec = $cmd;

                        return true;
                    })
                    ->andReturn("ok\nDPLY_EXIT:0");

                $cb($ssh);
            });

        $copier = new RepositoryTreeCopier($runner);
        $copier->copyTree($server, '/var/www/src-app', $server, '/var/www/dst-app');

        $this->assertIsString($capturedExec);
        $this->assertStringContainsString('rsync', $capturedExec);
        $this->assertStringContainsString('/var/www/src-app', $capturedExec);
        $this->assertStringContainsString('/var/www/dst-app', $capturedExec);
    }
}
