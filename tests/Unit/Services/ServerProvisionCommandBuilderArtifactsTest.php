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
        $server = Server::factory()->make([
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

    public function test_docker_role_stays_container_focused(): void
    {
        $server = Server::factory()->make([
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
