<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Support\Console\ConsoleArgspecs;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for the ConsoleArgspecs service.
 *
 * @covers \App\Support\Console\ConsoleArgspecs
 */
final class ConsoleArgspecsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a Server and seed a stack_summary artifact so ServerInstalledServices
     * resolves real tags (instead of fail-open 'unknown').
     */
    private function serverWithStack(array $summary): Server
    {
        $server = Server::factory()->create(['meta' => []]);
        $run = ServerProvisionRun::create([
            'server_id' => $server->id,
            'attempt' => 1,
            'status' => 'completed',
        ]);
        ServerProvisionArtifact::create([
            'server_provision_run_id' => $run->id,
            'type' => 'stack_summary',
            'key' => 'stack_summary',
            'label' => 'stack summary',
            'metadata' => $summary,
        ]);
        ServerInstalledServices::flushCaches();

        return $server;
    }

    public function test_argspecs_returns_array(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => [],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);

        $this->assertIsArray($argspecs);
    }

    public function test_systemctl_argspec_has_positional_arguments(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        $argspecs = ConsoleArgspecs::for($server);

        $this->assertArrayHasKey('systemctl', $argspecs);
        $this->assertArrayHasKey('positional', $argspecs['systemctl']);

        $positional = $argspecs['systemctl']['positional'];
        $this->assertArrayHasKey(1, $positional); // verbs
        $this->assertArrayHasKey(2, $positional); // units

        // Should have common verbs
        $this->assertContains('start', $positional[1]);
        $this->assertContains('stop', $positional[1]);
        $this->assertContains('restart', $positional[1]);
        $this->assertContains('reload', $positional[1]);
        $this->assertContains('status', $positional[1]);
        $this->assertContains('enable', $positional[1]);
        $this->assertContains('disable', $positional[1]);
    }

    public function test_systemctl_units_include_nginx_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['nginx'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('nginx', $units);
    }

    public function test_systemctl_units_include_apache_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['apache'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('apache2', $units);
    }

    public function test_systemctl_units_include_caddy_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['caddy'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('caddy', $units);
    }

    public function test_systemctl_units_include_php_fpm_when_php_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['php'],
                'php_version' => '8.3',
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('php8.3-fpm', $units);
    }

    public function test_systemctl_units_fallback_php_fpm_when_version_unknown(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['php'],
                // php_version intentionally missing
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('php-fpm', $units);
    }

    public function test_systemctl_units_include_mysql_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['mysql'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('mysql', $units);
        $this->assertContains('mariadb', $units);
    }

    public function test_systemctl_units_include_postgres_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['postgres'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('postgresql', $units);
    }

    public function test_systemctl_units_include_redis_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['redis'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('redis-server', $units);
    }

    public function test_systemctl_units_include_valkey_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['valkey'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('valkey-server', $units);
    }

    public function test_systemctl_units_include_memcached_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['memcached'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('memcached', $units);
    }

    public function test_systemctl_units_include_supervisor_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['supervisor'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('supervisor', $units);
    }

    public function test_systemctl_units_include_docker_when_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['docker'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('docker', $units);
    }

    public function test_systemctl_units_always_include_system_units(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        // These should always be present regardless of services
        $this->assertContains('cron', $units);
        $this->assertContains('ssh', $units);
        $this->assertContains('ufw', $units);
    }

    public function test_systemctl_units_are_unique(): void
    {
        // Test that units don't duplicate when multiple conditions match
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['nginx', 'php', 'mysql'],
                'php_version' => '8.3',
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertEquals($units, array_unique($units));
    }

    public function test_service_argspec_exists(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        $argspecs = ConsoleArgspecs::for($server);

        $this->assertArrayHasKey('service', $argspecs);
        $this->assertArrayHasKey('positional', $argspecs['service']);
    }

    public function test_journalctl_argspec_has_after_flag(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['nginx', 'php'],
                'php_version' => '8.3',
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);

        $this->assertArrayHasKey('journalctl', $argspecs);
        $this->assertArrayHasKey('after_flag', $argspecs['journalctl']);

        $afterFlag = $argspecs['journalctl']['after_flag'];
        $this->assertArrayHasKey('-u', $afterFlag);
        $this->assertArrayHasKey('--unit', $afterFlag);

        // Both should suggest same units
        $this->assertEquals($afterFlag['-u'], $afterFlag['--unit']);
        $this->assertContains('nginx', $afterFlag['-u']);
        $this->assertContains('php8.3-fpm', $afterFlag['-u']);
    }

    public function test_tail_argspec_has_log_paths(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['nginx'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);

        $this->assertArrayHasKey('tail', $argspecs);
        $this->assertArrayHasKey('positional', $argspecs['tail']);
        $this->assertArrayHasKey(1, $argspecs['tail']['positional']);

        $paths = $argspecs['tail']['positional'][1];
        $this->assertContains('/var/log/syslog', $paths);
        $this->assertContains('/var/log/auth.log', $paths);
        $this->assertContains('/var/log/nginx/error.log', $paths);
        $this->assertContains('/var/log/nginx/access.log', $paths);
    }

    public function test_less_argspec_matches_tail(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['nginx', 'php'],
                'php_version' => '8.3',
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);

        $this->assertArrayHasKey('less', $argspecs);
        $this->assertEquals(
            $argspecs['tail']['positional'][1],
            $argspecs['less']['positional'][1]
        );
    }

    public function test_php_log_paths_use_versioned_path(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['php'],
                'php_version' => '8.2',
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertContains('/var/log/php8.2-fpm.log', $paths);
    }

    public function test_php_log_paths_fallback_when_version_unknown(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['php'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertContains('/var/log/php-fpm.log', $paths);
    }

    public function test_caddy_log_paths_included_when_caddy_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['caddy'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertContains('/var/log/caddy/access.log', $paths);
    }

    public function test_mysql_log_paths_included_when_mysql_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['mysql'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertContains('/var/log/mysql/error.log', $paths);
    }

    public function test_postgres_log_paths_included_when_postgres_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['postgres'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertContains('/var/log/postgresql/', $paths);
    }

    public function test_redis_log_paths_included_when_redis_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['redis'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertContains('/var/log/redis/redis-server.log', $paths);
    }

    public function test_ufw_log_paths_included_when_ufw_installed(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['ufw'],
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertContains('/var/log/ufw.log', $paths);
    }

    public function test_empty_server_has_basic_argspecs(): void
    {
        $server = Server::factory()->create(['meta' => []]);

        $argspecs = ConsoleArgspecs::for($server);

        // Should always have systemctl and service
        $this->assertArrayHasKey('systemctl', $argspecs);
        $this->assertArrayHasKey('service', $argspecs);
        $this->assertArrayHasKey('journalctl', $argspecs);
        $this->assertArrayHasKey('tail', $argspecs);
        $this->assertArrayHasKey('less', $argspecs);
    }

    public function test_log_paths_are_unique(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['nginx', 'php', 'mysql', 'redis', 'ufw'],
                'php_version' => '8.3',
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $paths = $argspecs['tail']['positional'][1];

        $this->assertEquals($paths, array_unique($paths));
    }

    public function test_combined_server_has_all_relevant_units(): void
    {
        $server = Server::factory()->create([
            'meta' => [
                'expected_services' => ['nginx', 'php', 'mysql', 'redis', 'supervisor', 'docker'],
                'php_version' => '8.3',
            ],
        ]);

        $argspecs = ConsoleArgspecs::for($server);
        $units = $argspecs['systemctl']['positional'][2];

        $this->assertContains('nginx', $units);
        $this->assertContains('php8.3-fpm', $units);
        $this->assertContains('mysql', $units);
        $this->assertContains('redis-server', $units);
        $this->assertContains('supervisor', $units);
        $this->assertContains('docker', $units);
        $this->assertContains('cron', $units);
        $this->assertContains('ssh', $units);
        $this->assertContains('ufw', $units);
    }
}
