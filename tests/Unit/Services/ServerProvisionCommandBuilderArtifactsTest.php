<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerProvisionCommandBuilderArtifactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_rendered_config_and_verification_artifacts(): void
    {
        $server = new Server([
            'name' => 'App Server',
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $artifacts = app(ServerProvisionCommandBuilder::class)->buildArtifacts($server);

        $this->assertNotEmpty($artifacts);
        $this->assertTrue(collect($artifacts)->contains(fn (array $artifact): bool => $artifact['type'] === 'rendered_config' && $artifact['key'] === 'nginx-starter'));
        $this->assertTrue(collect($artifacts)->contains(fn (array $artifact): bool => $artifact['type'] === 'verification_plan'));
        $this->assertTrue(collect($artifacts)->contains(fn (array $artifact): bool => $artifact['type'] === 'rollback_plan'));
    }

    public function test_it_builds_apache_openlitespeed_and_traefik_artifacts(): void
    {
        $builder = app(ServerProvisionCommandBuilder::class);

        $apacheServer = new Server([
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'apache',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);
        $olsServer = new Server([
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'openlitespeed',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);
        $traefikServer = new Server([
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'traefik',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $this->assertTrue(collect($builder->buildArtifacts($apacheServer))->contains(fn (array $artifact): bool => $artifact['key'] === 'apache-starter'));
        $this->assertTrue(collect($builder->buildArtifacts($olsServer))->contains(fn (array $artifact): bool => $artifact['key'] === 'openlitespeed-starter'));
        $this->assertTrue(collect($builder->buildArtifacts($traefikServer))->contains(fn (array $artifact): bool => $artifact['key'] === 'traefik-starter'));
    }

    public function test_docker_role_stays_container_focused(): void
    {
        $server = new Server([
            'meta' => [
                'server_role' => 'docker',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $script = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

        $this->assertStringContainsString('Installing Docker', $script);
        $this->assertStringNotContainsString('Installing Composer', $script);
        $this->assertStringNotContainsString('Installing PHP 8.3', $script);
    }
}
