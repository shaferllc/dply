<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Contracts\DeployEngine;
use App\Services\Deploy\DeployEngineResolver;
use Tests\TestCase;

class DeployEngineResolverTest extends TestCase
{
    public function test_resolver_uses_docker_engine_for_docker_runtime_sites(): void
    {
        ['resolver' => $resolver, 'docker' => $docker] = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
        ]));

        $this->assertSame($docker, $resolver->forProject($project));
    }

    public function test_resolver_uses_kubernetes_engine_for_kubernetes_runtime_sites(): void
    {
        ['resolver' => $resolver, 'kubernetes' => $kubernetes] = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_KUBERNETES],
        ]));

        $this->assertSame($kubernetes, $resolver->forProject($project));
    }

    public function test_resolver_uses_functions_engine_for_functions_hosts(): void
    {
        ['resolver' => $resolver, 'functions' => $functions] = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]));

        $this->assertSame($functions, $resolver->forProject($project));
    }

    public function test_resolver_uses_aws_lambda_engine_for_lambda_hosts(): void
    {
        ['resolver' => $resolver, 'awsLambda' => $awsLambda] = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_AWS_LAMBDA],
        ]));

        $this->assertSame($awsLambda, $resolver->forProject($project));
    }

    public function test_resolver_falls_back_to_byo_engine_for_vm_sites(): void
    {
        ['resolver' => $resolver, 'byo' => $byo] = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_VM],
        ]));

        $this->assertSame($byo, $resolver->forProject($project));
    }

    /**
     * @return array{resolver: DeployEngineResolver, byo: DeployEngine, functions: DeployEngine, awsLambda: DeployEngine, docker: DeployEngine, kubernetes: DeployEngine}
     */
    private function makeResolver(): array
    {
        $byo = new class implements DeployEngine
        {
            public function run(\App\Services\Deploy\DeployContext $context): array
            {
                return ['output' => 'byo', 'sha' => null];
            }
        };
        $functions = new class implements DeployEngine
        {
            public function run(\App\Services\Deploy\DeployContext $context): array
            {
                return ['output' => 'functions', 'sha' => null];
            }
        };
        $awsLambda = new class implements DeployEngine
        {
            public function run(\App\Services\Deploy\DeployContext $context): array
            {
                return ['output' => 'aws', 'sha' => null];
            }
        };
        $docker = new class implements DeployEngine
        {
            public function run(\App\Services\Deploy\DeployContext $context): array
            {
                return ['output' => 'docker', 'sha' => null];
            }
        };
        $kubernetes = new class implements DeployEngine
        {
            public function run(\App\Services\Deploy\DeployContext $context): array
            {
                return ['output' => 'kubernetes', 'sha' => null];
            }
        };

        return [
            'resolver' => new DeployEngineResolver($byo, $functions, $awsLambda, $docker, $kubernetes),
            'byo' => $byo,
            'functions' => $functions,
            'awsLambda' => $awsLambda,
            'docker' => $docker,
            'kubernetes' => $kubernetes,
        ];
    }

    private function projectForServer(Server $server): Project
    {
        $site = new Site([
            'meta' => [],
        ]);
        $site->setRelation('server', $server);

        $project = new Project;
        $project->setRelation('site', $site);

        return $project;
    }
}
