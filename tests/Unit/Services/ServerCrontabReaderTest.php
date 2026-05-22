<?php


namespace Tests\Unit\Services\ServerCrontabReaderTest;
use App\Models\Server;
use \App\Services\SshConnection;
use \App\Services\Servers\ServerCrontabReader;
use PHPUnit\Framework\Attributes\Test;

it('rejects invalid linux usernames', function () {
    $server = new Server([
        'status' => Server::STATUS_READY,
        'ssh_private_key' => 'not-empty',
    ]);

    $reader = new ServerCrontabReader;

    $this->expectException(\InvalidArgumentException::class);
    $reader->readForUser($server, 'not;valid');
});

it('uses root before the server ssh user when fallback is enabled', function () {
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
        function exposedSshLoginCandidates(Server $server): array
        {
            return $this->sshLoginCandidates($server);
        }
    };

    expect($reader->exposedSshLoginCandidates($server))->toBe(['root', 'deploy']);
});

it('uses recovery role for root attempts and operational role for deploy attempts', function () {
    config()->set('server_cron.use_root_ssh', true);
    config()->set('server_cron.fallback_to_deploy_user_ssh', true);

    $server = new Server([
        'status' => Server::STATUS_READY,
        'ssh_user' => 'deploy',
        'ssh_private_key' => 'legacy-key',
    ]);

    $reader = new class extends ServerCrontabReader
    {
        function makeConnection(Server $server, string $loginUser): SshConnection
        {
            $role = $loginUser === 'root' ? SshConnection::ROLE_RECOVERY : SshConnection::ROLE_OPERATIONAL;
            $this->createdConnections[] = [$loginUser, $role];

            return new class($server, $loginUser, $role) extends SshConnection
            {
                function exec(string $command, int $timeoutSeconds = 120): string
                {
                    throw new \RuntimeException('stop after role capture');
                }

                function disconnect(): void
                {
                }
            };
        }
    };
    try {
        $reader->readForUser($server, 'deploy');
    } catch (\RuntimeException) {
    }

    expect($reader->createdConnections)->toBe([
        ['root', SshConnection::ROLE_RECOVERY],
        ['deploy', SshConnection::ROLE_OPERATIONAL],
    ]);
});
