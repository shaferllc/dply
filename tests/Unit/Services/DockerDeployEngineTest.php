<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Deploy\DeployContext;
use App\Services\Deploy\DockerDeployEngine;
use App\Services\Deploy\LocalDockerRuntimeManager;
use App\Services\SshConnectionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRemoteShell;
use Tests\TestCase;

class DockerDeployEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deploys_a_site_to_a_docker_host_over_ssh(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'ssh_private_key' => 'test-private-key',
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
            ],
        ]);
        $project = Project::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => 'Docker Demo',
            'slug' => 'docker-demo',
            'kind' => Project::KIND_BYO_SITE,
        ]);

        $site = Site::factory()->create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'type' => 'php',
            'slug' => 'docker-demo',
            'git_repository_url' => 'git@github.com:example/demo.git',
            'git_branch' => 'main',
            'repository_path' => '/srv/docker-demo',
        ]);

        $shell = new FakeRemoteShell(function (string $command): ?string {
            if (str_contains($command, 'git rev-parse HEAD')) {
                return "abc123def456\n";
            }

            if (str_contains($command, 'docker compose -f docker-compose.dply.yml up -d --build')) {
                return "Container docker-demo Started\n";
            }

            return null;
        });

        $this->mock(SshConnectionFactory::class, function ($mock) use ($shell): void {
            $mock->shouldReceive('forServer')->once()->andReturn($shell);
        });

        $engine = app(DockerDeployEngine::class);

        $result = $engine->run(new DeployContext(
            project: $project->fresh('site'),
            trigger: 'manual',
            apiIdempotencyHash: null,
            auditUserId: null,
        ));

        $site->refresh();

        $this->assertSame('abc123def456', $result['sha']);
        $this->assertStringContainsString('docker compose -f docker-compose.dply.yml up -d --build', implode("\n", array_column($shell->execCalls, 0)));
        $this->assertSame('/srv/docker-demo/docker-compose.dply.yml', $shell->putFiles[0][0]);
        $this->assertSame('/srv/docker-demo/Dockerfile.dply', $shell->putFiles[1][0]);
        $this->assertStringContainsString('build:', (string) data_get($site->meta, 'docker_runtime.compose_yaml'));
        $this->assertStringContainsString('FROM php:', (string) data_get($site->meta, 'docker_runtime.dockerfile'));
    }

    public function test_it_persists_discovered_local_docker_publication_and_runtime_details(): void
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'meta' => [
                'host_kind' => Server::HOST_KIND_DOCKER,
                'local_runtime' => [
                    'provider' => 'orbstack',
                ],
            ],
        ]);
        $project = Project::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'name' => 'Local Docker Demo',
            'slug' => 'local-docker-demo',
            'kind' => Project::KIND_BYO_SITE,
        ]);

        $site = Site::factory()->create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'type' => 'php',
            'slug' => 'laravel-repo',
            'git_repository_url' => 'https://github.com/example/demo.git',
            'git_branch' => 'main',
            'meta' => [
                'runtime_profile' => 'docker_web',
                'runtime_target' => [
                    'family' => 'local_orbstack_docker',
                    'platform' => 'local',
                    'provider' => 'orbstack',
                    'mode' => 'docker',
                    'status' => 'pending',
                    'logs' => [],
                ],
            ],
        ]);

        app()->instance(LocalDockerRuntimeManager::class, new class extends LocalDockerRuntimeManager
        {
            public function __construct() {}

            public function deploy(Site $site): array
            {
                return [
                    'output' => 'Local Docker deploy completed.',
                    'sha' => 'abc123local',
                    'status' => 'running',
                    'logs' => ['container started'],
                    'compose_yaml' => "services:\n  app:\n    image: demo\n",
                    'dockerfile' => "FROM php:8.3-cli\n",
                    'workspace_path' => '/tmp/local-runtime',
                    'repository_checkout_path' => '/tmp/local-runtime/repo',
                    'working_directory' => '/tmp/local-runtime/repo',
                    'generated_compose_path' => '/tmp/local-runtime/repo/docker-compose.dply.yml',
                    'generated_dockerfile_path' => '/tmp/local-runtime/repo/Dockerfile.dply',
                    'publication' => [
                        'hostname' => 'laravel.repo.orb.local',
                        'url' => 'http://laravel.repo.orb.local',
                        'container_ip' => '192.168.107.2',
                    ],
                    'runtime_details' => [
                        'containers' => [[
                            'id' => 'container-123',
                            'name' => 'laravel.repo',
                            'service' => 'app',
                            'hostname' => 'laravel.repo',
                            'orb_hostname' => 'laravel.repo.orb.local',
                            'ipv4' => '192.168.107.2',
                        ]],
                    ],
                ];
            }
        });

        $engine = app(DockerDeployEngine::class);

        $result = $engine->run(new DeployContext(
            project: $project->fresh('site'),
            trigger: 'manual',
            apiIdempotencyHash: null,
            auditUserId: null,
        ));

        $site->refresh();

        $this->assertSame('abc123local', $result['sha']);
        $this->assertSame('laravel.repo.orb.local', data_get($site->meta, 'runtime_target.publication.hostname'));
        $this->assertSame('http://laravel.repo.orb.local', data_get($site->meta, 'runtime_target.publication.url'));
        $this->assertSame('192.168.107.2', data_get($site->meta, 'runtime_target.publication.container_ip'));
        $this->assertSame('laravel.repo.orb.local', data_get($site->meta, 'docker_runtime.runtime_details.containers.0.orb_hostname'));
        $this->assertSame('192.168.107.2', data_get($site->meta, 'docker_runtime.runtime_details.containers.0.ipv4'));
    }
}
