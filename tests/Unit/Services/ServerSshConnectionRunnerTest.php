<?php


namespace Tests\Unit\Services\ServerSshConnectionRunnerTest;
use App\Models\Server;
use \App\Services\SshConnection;
use \App\Services\Servers\ServerSshConnectionRunner;

test('root first fallback uses recovery then operational roles', function () {
    $server = new Server([
        'ssh_user' => 'deploy',
    ]);

    $runner = new class extends ServerSshConnectionRunner
    {
        function makeConnection(Server $server, string $loginUser, string $credentialRole): SshConnection
        {
            $this->created[] = [$loginUser, $credentialRole];

            return new class($server, $loginUser, $credentialRole) extends SshConnection
            {
                function disconnect(): void
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

    expect($runner->created)->toBe([
        ['root', 'recovery'],
        ['deploy', 'operational'],
    ]);
});
