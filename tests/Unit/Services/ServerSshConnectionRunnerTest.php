<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\SshConnection;
use App\Services\Servers\ServerSshConnectionRunner;
use Tests\TestCase;

class ServerSshConnectionRunnerTest extends TestCase
{
    public function test_root_first_fallback_uses_recovery_then_operational_roles(): void
    {
        $server = new Server([
            'ssh_user' => 'deploy',
        ]);

        $runner = new class extends ServerSshConnectionRunner
        {
            public array $created = [];

            protected function makeConnection(Server $server, string $loginUser, string $credentialRole): SshConnection
            {
                $this->created[] = [$loginUser, $credentialRole];

                return new class($server, $loginUser, $credentialRole) extends SshConnection
                {
                    public function disconnect(): void
                    {
                    }
                };
            }
        };

        try {
            $runner->run($server, function () {
                throw new \RuntimeException('first attempt failed');
            }, true, true);
        } catch (\RuntimeException) {
        }

        $this->assertSame([
            ['root', 'recovery'],
            ['deploy', 'operational'],
        ], $runner->created);
    }
}
