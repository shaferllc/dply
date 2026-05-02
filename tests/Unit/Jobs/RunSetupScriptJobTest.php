<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use Tests\TestCase;

class RunSetupScriptJobTest extends TestCase
{
    public function test_docker_hosts_do_not_dispatch_vm_setup_scripts(): void
    {
        $server = new Server([
            'status' => Server::STATUS_READY,
            'ip_address' => '203.0.113.20',
            'ssh_private_key' => 'fake-key',
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'server_role' => 'docker',
            ],
        ]);

        $this->assertFalse(RunSetupScriptJob::shouldDispatch($server));
    }
}
