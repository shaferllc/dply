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
}
