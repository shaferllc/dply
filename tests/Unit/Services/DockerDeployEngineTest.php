<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DockerDeployEngineTest;

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

uses(RefreshDatabase::class);

test('it deploys a site to a docker host over ssh', function () {
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

    expect($result['sha'])->toBe('abc123def456');
    $this->assertStringContainsString('docker compose -f docker-compose.dply.yml up -d --build', implode("\n", array_column($shell->execCalls, 0)));
    expect($shell->putFiles[0][0])->toBe('/srv/docker-demo/docker-compose.dply.yml');
    expect($shell->putFiles[1][0])->toBe('/srv/docker-demo/Dockerfile.dply');
    $this->assertStringContainsString('build:', (string) data_get($site->meta, 'docker_runtime.compose_yaml'));
    $this->assertStringContainsString('FROM php:', (string) data_get($site->meta, 'docker_runtime.dockerfile'));
});
test('it persists discovered local docker publication and runtime details', function () {
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

    expect($result['sha'])->toBe('abc123local');
    expect(data_get($site->meta, 'runtime_target.publication.hostname'))->toBe('laravel.repo.orb.local');
    expect(data_get($site->meta, 'runtime_target.publication.url'))->toBe('http://laravel.repo.orb.local');
    expect(data_get($site->meta, 'runtime_target.publication.container_ip'))->toBe('192.168.107.2');
    expect(data_get($site->meta, 'docker_runtime.runtime_details.containers.0.orb_hostname'))->toBe('laravel.repo.orb.local');
    expect(data_get($site->meta, 'docker_runtime.runtime_details.containers.0.ipv4'))->toBe('192.168.107.2');
});
