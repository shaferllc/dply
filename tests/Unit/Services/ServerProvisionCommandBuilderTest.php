<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerProvisionCommandBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_returns_empty_without_server_role_meta(): void
    {
        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => ['digitalocean' => ['ipv6' => false]],
        ]);

        $commands = app(ServerProvisionCommandBuilder::class)->build($server);

        $this->assertSame([], $commands);
    }

    public function test_build_application_stack_includes_nginx_php_and_mysql_packages(): void
    {
        config(['server_provision.install_supervisor_on_provision' => false]);

        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $commands = app(ServerProvisionCommandBuilder::class)->build($server);

        $joined = implode("\n", $commands);
        $this->assertStringContainsString('nginx', $joined);
        $this->assertStringContainsString('ondrej/php', $joined);
        $this->assertStringContainsString('php8.3-fpm', $joined);
        $this->assertStringContainsString('mysql-server', $joined);
        $this->assertStringContainsString('redis-server', $joined);
        $this->assertStringNotContainsString('apt-get install -y --no-install-recommends supervisor', $joined);
    }

    public function test_build_application_stack_installs_supervisor_when_provision_flag_enabled(): void
    {
        config(['server_provision.install_supervisor_on_provision' => true]);

        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $commands = app(ServerProvisionCommandBuilder::class)->build($server);
        $joined = implode("\n", $commands);

        $this->assertStringContainsString('apt-get install -y --no-install-recommends supervisor', $joined);
        $this->assertStringContainsString('systemctl enable --now supervisor', $joined);
    }

    public function test_build_load_balancer_installs_haproxy(): void
    {
        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'load_balancer',
                'webserver' => 'none',
                'php_version' => 'none',
                'database' => 'none',
                'cache_service' => 'redis',
            ],
        ]);

        $commands = app(ServerProvisionCommandBuilder::class)->build($server);
        $joined = implode("\n", $commands);

        $this->assertStringContainsString('haproxy', $joined);
        $this->assertStringNotContainsString('ondrej/php', $joined);
    }

    public function test_build_emits_named_step_markers_for_setup_progress(): void
    {
        $keyPath = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');

        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'ssh_private_key' => file_get_contents($keyPath),
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $commands = app(ServerProvisionCommandBuilder::class)->build($server);
        $joined = implode("\n", $commands);

        $this->assertStringContainsString('[dply-step] Installing system updates', $joined);
        $this->assertStringContainsString('[dply-step] Installing base packages', $joined);
        $this->assertStringContainsString('[dply-step] Creating server user', $joined);
        $this->assertStringContainsString('[dply-step] Installing webserver', $joined);
        $this->assertStringContainsString('[dply-step] Installing PHP 8.3', $joined);
        $this->assertStringContainsString('[dply-step] Installing MySQL', $joined);
        $this->assertStringContainsString('[dply-step] Installing Redis', $joined);
        $this->assertStringContainsString('[dply-step] Installing Composer', $joined);
        $this->assertStringContainsString('[dply-step] Finalizing server', $joined);
    }
}
