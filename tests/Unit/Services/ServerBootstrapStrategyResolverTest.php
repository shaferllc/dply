<?php


namespace Tests\Unit\Services\ServerBootstrapStrategyResolverTest;
use App\Models\Server;
use App\Services\Servers\Bootstrap\DockerHostBootstrapStrategy;
use App\Services\Servers\Bootstrap\KubernetesClusterBootstrapStrategy;
use App\Services\Servers\Bootstrap\ServerBootstrapStrategyResolver;
use App\Services\Servers\Bootstrap\VmServerBootstrapStrategy;
use PHPUnit\Framework\MockObject\MockObject;

test('resolver returns vm strategy for vm hosts', function () {
    $resolver = makeResolver();
    $server = new Server(['meta' => ['host_kind' => Server::HOST_KIND_VM]]);

    expect($resolver->for($server))->toBeInstanceOf(VmServerBootstrapStrategy::class);
});

test('resolver returns docker strategy for docker hosts', function () {
    $resolver = makeResolver();
    $server = new Server(['meta' => ['host_kind' => Server::HOST_KIND_DOCKER]]);

    expect($resolver->for($server))->toBeInstanceOf(DockerHostBootstrapStrategy::class);
});

test('resolver returns kubernetes strategy for kubernetes hosts', function () {
    $resolver = makeResolver();
    $server = new Server(['meta' => ['host_kind' => Server::HOST_KIND_KUBERNETES]]);

    expect($resolver->for($server))->toBeInstanceOf(KubernetesClusterBootstrapStrategy::class);
});

function makeResolver(): ServerBootstrapStrategyResolver
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
