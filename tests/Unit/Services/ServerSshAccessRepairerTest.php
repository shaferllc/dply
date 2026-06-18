<?php

namespace Tests\Unit\Services\ServerSshAccessRepairerTest;

use App\Models\Server;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerSshAccessRepairer;
use Mockery;

test('repair uses root recovery connection and reinstalls operational key', function () {
    $keyPath = base_path('app/Modules/TaskRunner/Tests/fixtures/private_key.pem');
    $server = new Server([
        'name' => 'repair-target',
        'ip_address' => '203.0.113.10',
        'ssh_user' => 'dply',
        'ssh_private_key' => file_get_contents($keyPath),
        'ssh_recovery_private_key' => file_get_contents($keyPath),
        'ssh_operational_private_key' => file_get_contents($keyPath),
    ]);

    $remote = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runScript')
        ->once()
        ->withArgs(function (Server $passedServer, string $name, string $script, int $timeout, bool $asRoot) use ($server): bool {
            return $passedServer === $server
                && $name === 'Repair SSH access'
                && $timeout === 60
                && $asRoot === true
                && str_contains($script, 'authorized_keys')
                && str_contains($script, base64_encode((string) $server->openSshPublicKeyFromOperationalPrivate()));
        })
        ->andReturn(new ProcessOutput("repair ok\n"));

    $repairer = new ServerSshAccessRepairer($remote);

    expect($repairer->repairOperationalAccess($server))->toBe("repair ok\n");
});
