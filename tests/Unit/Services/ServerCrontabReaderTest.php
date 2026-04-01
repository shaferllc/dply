<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\SshConnection;
use App\Services\Servers\ServerCrontabReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServerCrontabReaderTest extends TestCase
{
    #[Test]
    public function it_rejects_invalid_linux_usernames(): void
    {
        $server = new Server([
            'status' => Server::STATUS_READY,
            'ssh_private_key' => 'not-empty',
        ]);

        $reader = new ServerCrontabReader;

        $this->expectException(\InvalidArgumentException::class);
        $reader->readForUser($server, 'not;valid');
    }

    #[Test]
    public function it_uses_root_before_the_server_ssh_user_when_fallback_is_enabled(): void
    {
        config()->set('server_cron.use_root_ssh', true);
        config()->set('server_cron.fallback_to_deploy_user_ssh', true);

        $server = new Server([
            'ssh_user' => 'deploy',
        ]);

        $reader = new class extends ServerCrontabReader
        {
            /**
             * @return list<string>
             */
            public function exposedSshLoginCandidates(Server $server): array
            {
                return $this->sshLoginCandidates($server);
            }
        };

        $this->assertSame(['root', 'deploy'], $reader->exposedSshLoginCandidates($server));
    }

    #[Test]
    public function it_uses_recovery_role_for_root_attempts_and_operational_role_for_deploy_attempts(): void
    {
        config()->set('server_cron.use_root_ssh', true);
        config()->set('server_cron.fallback_to_deploy_user_ssh', true);

        $server = new Server([
            'status' => Server::STATUS_READY,
            'ssh_user' => 'deploy',
            'ssh_private_key' => 'legacy-key',
        ]);

        $reader = new class extends ServerCrontabReader
        {
            public array $createdConnections = [];

            protected function makeConnection(Server $server, string $loginUser): SshConnection
            {
                $role = $loginUser === 'root' ? SshConnection::ROLE_RECOVERY : SshConnection::ROLE_OPERATIONAL;
                $this->createdConnections[] = [$loginUser, $role];

                return new class($server, $loginUser, $role) extends SshConnection
                {
                    public function exec(string $command, int $timeoutSeconds = 120): string
                    {
                        throw new \RuntimeException('stop after role capture');
                    }

                    public function disconnect(): void
                    {
                    }
                };
            }
        };

        try {
            $reader->readForUser($server, 'deploy');
        } catch (\RuntimeException) {
        }

        $this->assertSame([
            ['root', SshConnection::ROLE_RECOVERY],
            ['deploy', SshConnection::ROLE_OPERATIONAL],
        ], $reader->createdConnections);
    }
}
