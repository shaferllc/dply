<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\Site;
use Tests\TestCase;

class SiteRuntimeProfileTest extends TestCase
{
    public function test_sites_on_docker_hosts_default_to_docker_runtime_profile(): void
    {
        $server = new Server([
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);

        $site = new Site;
        $site->setRelation('server', $server);

        $this->assertSame('docker_web', $site->runtimeProfile());
        $this->assertTrue($site->usesDockerRuntime());
        $this->assertFalse($site->usesKubernetesRuntime());
    }

    public function test_sites_on_kubernetes_hosts_default_to_kubernetes_runtime_profile(): void
    {
        $server = new Server([
            'meta' => [
                'host_kind' => Server::HOST_KIND_KUBERNETES,
            ],
        ]);

        $site = new Site;
        $site->setRelation('server', $server);

        $this->assertSame('kubernetes_web', $site->runtimeProfile());
        $this->assertTrue($site->usesKubernetesRuntime());
        $this->assertFalse($site->usesDockerRuntime());
    }
}
