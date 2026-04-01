<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\ByoServerDeployEngine;
use App\Services\Deploy\DockerComposeArtifactBuilder;
use App\Services\Deploy\DeployEngineResolver;
use App\Services\Deploy\DigitalOceanFunctionsDeployEngine;
use App\Services\Deploy\DockerDeployEngine;
use App\Services\Deploy\KubernetesManifestBuilder;
use App\Services\Deploy\KubernetesDeployEngine;
use App\Services\Sites\SiteGitDeployer;
use Tests\TestCase;

class DeployEngineResolverTest extends TestCase
{
    public function test_resolver_uses_docker_engine_for_docker_runtime_sites(): void
    {
        $resolver = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
        ]));

        $this->assertInstanceOf(DockerDeployEngine::class, $resolver->forProject($project));
    }

    public function test_resolver_uses_kubernetes_engine_for_kubernetes_runtime_sites(): void
    {
        $resolver = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_KUBERNETES],
        ]));

        $this->assertInstanceOf(KubernetesDeployEngine::class, $resolver->forProject($project));
    }

    public function test_resolver_uses_functions_engine_for_functions_hosts(): void
    {
        $resolver = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]));

        $this->assertInstanceOf(DigitalOceanFunctionsDeployEngine::class, $resolver->forProject($project));
    }

    public function test_resolver_falls_back_to_byo_engine_for_vm_sites(): void
    {
        $resolver = $this->makeResolver();
        $project = $this->projectForServer(new Server([
            'meta' => ['host_kind' => Server::HOST_KIND_VM],
        ]));

        $this->assertInstanceOf(ByoServerDeployEngine::class, $resolver->forProject($project));
    }

    private function makeResolver(): DeployEngineResolver
    {
        $byo = new ByoServerDeployEngine($this->createMock(SiteGitDeployer::class));
        $functions = app(DigitalOceanFunctionsDeployEngine::class);
        $docker = new DockerDeployEngine(new DockerComposeArtifactBuilder);
        $kubernetes = new KubernetesDeployEngine(new KubernetesManifestBuilder);

        return new DeployEngineResolver($byo, $functions, $docker, $kubernetes);
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
