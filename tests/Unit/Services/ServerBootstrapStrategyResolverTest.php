<?php

namespace Tests\Unit\Services\ServerBootstrapStrategyResolverTest;

use App\Models\Server;
use App\Services\Servers\Bootstrap\DockerHostBootstrapStrategy;
use App\Services\Servers\Bootstrap\KubernetesClusterBootstrapStrategy;
use App\Services\Servers\Bootstrap\ServerBootstrapStrategyResolver;
use App\Services\Servers\Bootstrap\VmServerBootstrapStrategy;

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
    $vm = \Mockery::mock(VmServerBootstrapStrategy::class);
    $vm->shouldReceive('supports')->andReturnUsing(
        fn (Server $server): bool => $server->isVmHost()
    );

    $docker = \Mockery::mock(DockerHostBootstrapStrategy::class);
    $docker->shouldReceive('supports')->andReturnUsing(
        fn (Server $server): bool => $server->isDockerHost()
    );

    $kubernetes = \Mockery::mock(KubernetesClusterBootstrapStrategy::class);
    $kubernetes->shouldReceive('supports')->andReturnUsing(
        fn (Server $server): bool => $server->isKubernetesCluster()
    );

    return new ServerBootstrapStrategyResolver([$vm, $docker, $kubernetes]);
}
