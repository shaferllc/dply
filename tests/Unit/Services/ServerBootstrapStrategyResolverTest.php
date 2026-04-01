<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\Bootstrap\KubernetesClusterBootstrapStrategy;
use App\Services\Servers\Bootstrap\ServerBootstrapStrategyResolver;
use App\Services\Servers\Bootstrap\VmServerBootstrapStrategy;
use App\Services\Servers\Bootstrap\DockerHostBootstrapStrategy;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ServerBootstrapStrategyResolverTest extends TestCase
{
    public function test_resolver_returns_vm_strategy_for_vm_hosts(): void
    {
        $resolver = $this->makeResolver();
        $server = new Server(['meta' => ['host_kind' => Server::HOST_KIND_VM]]);

        $this->assertInstanceOf(VmServerBootstrapStrategy::class, $resolver->for($server));
    }

    public function test_resolver_returns_docker_strategy_for_docker_hosts(): void
    {
        $resolver = $this->makeResolver();
        $server = new Server(['meta' => ['host_kind' => Server::HOST_KIND_DOCKER]]);

        $this->assertInstanceOf(DockerHostBootstrapStrategy::class, $resolver->for($server));
    }

    public function test_resolver_returns_kubernetes_strategy_for_kubernetes_hosts(): void
    {
        $resolver = $this->makeResolver();
        $server = new Server(['meta' => ['host_kind' => Server::HOST_KIND_KUBERNETES]]);

        $this->assertInstanceOf(KubernetesClusterBootstrapStrategy::class, $resolver->for($server));
    }

    private function makeResolver(): ServerBootstrapStrategyResolver
    {
        /** @var VmServerBootstrapStrategy&MockObject $vm */
        $vm = $this->createMock(VmServerBootstrapStrategy::class);
        $vm->method('supports')->willReturnCallback(
            fn (Server $server): bool => $server->isVmHost()
        );

        /** @var DockerHostBootstrapStrategy&MockObject $docker */
        $docker = $this->createMock(DockerHostBootstrapStrategy::class);
        $docker->method('supports')->willReturnCallback(
            fn (Server $server): bool => $server->isDockerHost()
        );

        /** @var KubernetesClusterBootstrapStrategy&MockObject $kubernetes */
        $kubernetes = $this->createMock(KubernetesClusterBootstrapStrategy::class);
        $kubernetes->method('supports')->willReturnCallback(
            fn (Server $server): bool => $server->isKubernetesCluster()
        );

        return new ServerBootstrapStrategyResolver([$vm, $docker, $kubernetes]);
    }
}
