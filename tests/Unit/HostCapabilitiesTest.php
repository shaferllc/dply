<?php

namespace Tests\Unit;

use App\Models\Server;
use Tests\TestCase;

class HostCapabilitiesTest extends TestCase
{
    public function test_vm_hosts_keep_ssh_capabilities_by_default(): void
    {
        $server = new Server([
            'meta' => [],
        ]);

        $capabilities = $server->hostCapabilities();

        $this->assertTrue($server->isVmHost());
        $this->assertTrue($capabilities->supportsSsh());
        $this->assertTrue($capabilities->supportsNginxProvisioning());
        $this->assertFalse($capabilities->supportsFunctionDeploy());
    }

    public function test_digitalocean_functions_hosts_disable_machine_features(): void
    {
        $server = new Server([
            'meta' => [
                'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            ],
        ]);

        $capabilities = $server->hostCapabilities();

        $this->assertTrue($server->isDigitalOceanFunctionsHost());
        $this->assertFalse($capabilities->supportsSsh());
        $this->assertFalse($capabilities->supportsNginxProvisioning());
        $this->assertFalse($capabilities->supportsEnvPushToHost());
        $this->assertTrue($capabilities->supportsFunctionDeploy());
        $this->assertSame('DigitalOcean Functions', $server->providerDisplayLabel());
    }
}
