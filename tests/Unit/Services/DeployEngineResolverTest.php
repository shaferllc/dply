<?php

namespace Tests\Unit\Services\DeployEngineResolverTest;

use App\Contracts\DeployEngine;
use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Services\DeployContext;
use App\Modules\Deploy\Services\DeployEngineResolver;

test('resolver uses docker engine for docker runtime sites', function () {
    ['resolver' => $resolver, 'docker' => $docker] = makeResolver();
    $project = projectForServer(new Server([
        'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
    ]), runtimeProfile: 'docker_web');

    expect($resolver->forProject($project))->toBe($docker);
});

test('resolver uses kubernetes engine for kubernetes runtime sites', function () {
    ['resolver' => $resolver, 'kubernetes' => $kubernetes] = makeResolver();
    $project = projectForServer(new Server([
        'meta' => ['host_kind' => Server::HOST_KIND_KUBERNETES],
    ]), runtimeProfile: 'kubernetes_web');

    expect($resolver->forProject($project))->toBe($kubernetes);
});

test('resolver falls back to byo engine for leftover functions hosts', function () {
    ['resolver' => $resolver, 'byo' => $byo] = makeResolver();
    $project = projectForServer(new Server([
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]));

    expect($resolver->forProject($project))->toBe($byo);
});

test('resolver falls back to byo engine for leftover lambda hosts', function () {
    ['resolver' => $resolver, 'byo' => $byo] = makeResolver();
    $project = projectForServer(new Server([
        'meta' => ['host_kind' => Server::HOST_KIND_AWS_LAMBDA],
    ]));

    expect($resolver->forProject($project))->toBe($byo);
});

test('resolver falls back to byo engine for vm sites', function () {
    ['resolver' => $resolver, 'byo' => $byo] = makeResolver();
    $project = projectForServer(new Server([
        'meta' => ['host_kind' => Server::HOST_KIND_VM],
    ]));

    expect($resolver->forProject($project))->toBe($byo);
});

/**
 * @return array{resolver: DeployEngineResolver, byo: DeployEngine, docker: DeployEngine, kubernetes: DeployEngine}
 */
function makeResolver(): array
{
    $byo = new class implements DeployEngine
    {
        public function run(DeployContext $context): array
        {
            return ['output' => 'byo', 'sha' => null];
        }
    };
    $docker = new class implements DeployEngine
    {
        public function run(DeployContext $context): array
        {
            return ['output' => 'docker', 'sha' => null];
        }
    };
    $kubernetes = new class implements DeployEngine
    {
        public function run(DeployContext $context): array
        {
            return ['output' => 'kubernetes', 'sha' => null];
        }
    };

    return [
        'resolver' => new DeployEngineResolver($byo, $docker, $kubernetes),
        'byo' => $byo,
        'docker' => $docker,
        'kubernetes' => $kubernetes,
    ];
}

function projectForServer(Server $server, ?string $runtimeProfile = null): Project
{
    $site = new Site([
        'meta' => $runtimeProfile !== null ? ['runtime_profile' => $runtimeProfile] : [],
    ]);
    $site->setRelation('server', $server);

    $project = new Project;
    $project->setRelation('site', $site);

    return $project;
}
