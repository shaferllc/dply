<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\RSA;
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

    public function test_build_application_stack_supports_apache_openlitespeed_and_traefik(): void
    {
        $builder = app(ServerProvisionCommandBuilder::class);

        $apache = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'apache',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);
        $openlitespeed = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'openlitespeed',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);
        $traefik = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'traefik',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]);

        $apacheCommands = implode("\n", $builder->build($apache));
        $olsCommands = implode("\n", $builder->build($openlitespeed));
        $traefikCommands = implode("\n", $builder->build($traefik));

        $this->assertStringContainsString('apache2', $apacheCommands);
        $this->assertStringContainsString('apachectl configtest', $apacheCommands);

        $this->assertStringContainsString('openlitespeed', $olsCommands);
        $this->assertStringContainsString('lswsctrl', $olsCommands);

        $this->assertStringContainsString('traefik', $traefikCommands);
        $this->assertStringContainsString('caddy', $traefikCommands);
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

    public function test_build_emits_repeat_safe_install_checks_for_reruns(): void
    {
        config([
            'server_provision.force_reinstall' => false,
            'server_provision.install_supervisor_on_provision' => false,
        ]);

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

        $this->assertStringContainsString('[dply] nginx already installed; skipping package install.', $joined);
        $this->assertStringContainsString('[dply] mysql-server already installed; skipping package install.', $joined);
        $this->assertStringContainsString('[dply] redis-server already installed; skipping package install.', $joined);
        $this->assertStringContainsString('grep -RqsE', $joined);
        $this->assertStringContainsString('ondrej-ubuntu-php|ppa\\.launchpadcontent\\.net/ondrej/php', $joined);
        $this->assertStringContainsString('[dply] ondrej/php repository already installed; skipping repository setup.', $joined);
        $this->assertStringContainsString('/etc/apt/keyrings/ondrej-php.gpg', $joined);
        $this->assertStringContainsString('https://ppa.launchpadcontent.net/ondrej/php/ubuntu', $joined);
        $this->assertStringContainsString('timeout 300s apt-get update -y', $joined);
        $this->assertStringNotContainsString('rg -l "ondrej-ubuntu-php|ppa.launchpadcontent.net/ondrej/php"', $joined);
        $this->assertStringContainsString('command -v composer >/dev/null 2>&1', $joined);
        $this->assertStringContainsString('[dply] composer already installed; skipping installer.', $joined);
    }

    public function test_build_application_stack_installs_mise_for_non_php_runtimes(): void
    {
        config(['server_provision.install_mise_on_provision' => true]);

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

        $joined = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

        $this->assertStringContainsString('Installing mise', $joined);
        $this->assertStringContainsString('mise.jdx.dev/gpg-key.pub', $joined);
        $this->assertStringContainsString('apt-get install -y --no-install-recommends mise', $joined);
        $this->assertStringContainsString('# dply: mise activation', $joined);
    }

    public function test_build_application_stack_pins_runtime_defaults_via_mise_use_global(): void
    {
        config(['server_provision.install_mise_on_provision' => true]);

        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
                'runtime_defaults' => [
                    'node' => '22',
                    'python' => '3.12',
                    'ruby' => '3.3',
                    'go' => '1.22',
                ],
            ],
        ]);

        $joined = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

        $this->assertStringContainsString('mise use --global node@22', $joined);
        $this->assertStringContainsString('mise use --global python@3.12', $joined);
        $this->assertStringContainsString('mise use --global ruby@3.3', $joined);
        $this->assertStringContainsString('mise use --global go@1.22', $joined);
    }

    public function test_build_application_stack_skips_unknown_runtime_defaults(): void
    {
        config(['server_provision.install_mise_on_provision' => true]);

        // PHP and unknown runtimes silently no-op via MiseInstallScriptBuilder's
        // SUPPORTED_RUNTIMES guard — bootstrap script must not try to mise-install
        // PHP (ondrej/php handles that path) or invent commands for runtimes we
        // don't know.
        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'meta' => [
                'server_role' => 'application',
                'webserver' => 'nginx',
                'php_version' => '8.3',
                'database' => 'mysql84',
                'cache_service' => 'redis',
                'runtime_defaults' => [
                    'php' => '8.3',
                    'erlang' => '27',
                    'node' => '22',
                ],
            ],
        ]);

        $joined = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

        $this->assertStringContainsString('mise use --global node@22', $joined);
        $this->assertStringNotContainsString('mise use --global php@', $joined);
        $this->assertStringNotContainsString('mise use --global erlang@', $joined);
    }

    public function test_build_application_stack_skips_mise_when_disabled_via_config(): void
    {
        config(['server_provision.install_mise_on_provision' => false]);

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

        $joined = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

        $this->assertStringNotContainsString('Installing mise', $joined);
        $this->assertStringNotContainsString('mise.jdx.dev', $joined);
    }

    public function test_build_can_force_reinstall_via_config(): void
    {
        config([
            'server_provision.force_reinstall' => true,
            'server_provision.install_supervisor_on_provision' => true,
        ]);

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

        $this->assertStringContainsString('apt-get install -y --no-install-recommends nginx', $joined);
        $this->assertStringContainsString('apt-get install -y --no-install-recommends mysql-server', $joined);
        $this->assertStringContainsString('apt-get install -y --no-install-recommends redis-server', $joined);
        $this->assertStringContainsString('/etc/apt/keyrings/ondrej-php.gpg', $joined);
        $this->assertStringContainsString('https://ppa.launchpadcontent.net/ondrej/php/ubuntu', $joined);
        $this->assertStringContainsString('curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer', $joined);
        $this->assertStringNotContainsString('already installed; skipping package install.', $joined);
        $this->assertStringNotContainsString('already installed; skipping repository setup.', $joined);
        $this->assertStringNotContainsString('already installed; skipping installer.', $joined);
    }

    public function test_build_uses_operational_public_key_for_deploy_user_bootstrap_when_present(): void
    {
        $keyPath = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');
        $recoveryKey = RSA::createKey(2048)->toString('OpenSSH');

        $server = Server::factory()->create([
            'provider' => ServerProvider::DigitalOcean,
            'ssh_private_key' => $recoveryKey,
            'ssh_recovery_private_key' => $recoveryKey,
            'ssh_operational_private_key' => file_get_contents($keyPath),
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

        $this->assertStringContainsString(base64_encode((string) $server->openSshPublicKeyFromOperationalPrivate()), $joined);
        $this->assertStringNotContainsString(base64_encode((string) $server->openSshPublicKeyFromRecoveryPrivate()), $joined);
    }
}
