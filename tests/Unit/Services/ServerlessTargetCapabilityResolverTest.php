<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use Tests\TestCase;

class ServerlessTargetCapabilityResolverTest extends TestCase
{
    public function test_digitalocean_functions_advertises_all_four_openwhisk_runtimes(): void
    {
        $server = (new Server)->forceFill([
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);

        $capabilities = (new ServerlessTargetCapabilityResolver)->forServer($server);

        // DO Functions = managed Apache OpenWhisk: Node, Python, PHP, Go.
        $this->assertTrue($capabilities['supports_php_runtime']);
        $this->assertTrue($capabilities['supports_node_runtime']);
        $this->assertTrue($capabilities['supports_python_runtime']);
        $this->assertTrue($capabilities['supports_go_runtime']);
    }

    public function test_aws_lambda_advertises_all_four_runtimes(): void
    {
        $server = (new Server)->forceFill([
            'meta' => ['host_kind' => Server::HOST_KIND_AWS_LAMBDA],
        ]);

        $capabilities = (new ServerlessTargetCapabilityResolver)->forServer($server);

        $this->assertTrue($capabilities['supports_php_runtime']);
        $this->assertTrue($capabilities['supports_node_runtime']);
        $this->assertTrue($capabilities['supports_python_runtime']);
        $this->assertTrue($capabilities['supports_go_runtime']);
    }

    public function test_unknown_target_advertises_no_runtimes(): void
    {
        $capabilities = (new ServerlessTargetCapabilityResolver)->forServer(null);

        $this->assertSame('unknown', $capabilities['target']);
        $this->assertFalse($capabilities['supports_php_runtime']);
        $this->assertFalse($capabilities['supports_node_runtime']);
        $this->assertFalse($capabilities['supports_python_runtime']);
        $this->assertFalse($capabilities['supports_go_runtime']);
    }
}
