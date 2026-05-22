<?php

namespace Tests\Unit\Jobs\RunSetupScriptJobTest;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;

test('docker hosts do not dispatch vm setup scripts', function () {
    $server = new Server([
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.20',
        'ssh_private_key' => 'fake-key',
        'meta' => [
            'host_kind' => Server::HOST_KIND_DOCKER,
            'server_role' => 'docker',
        ],
    ]);

    expect(RunSetupScriptJob::shouldDispatch($server))->toBeFalse();
});
