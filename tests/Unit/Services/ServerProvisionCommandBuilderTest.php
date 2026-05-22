<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerProvisionCommandBuilderTest;
use App\Enums\ServerProvider;
use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;
use phpseclib3\Crypt\RSA;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('build returns empty without server role meta', function () {
    $server = Server::factory()->create([
        'provider' => ServerProvider::DigitalOcean,
        'meta' => ['digitalocean' => ['ipv6' => false]],
    ]);

    $commands = app(ServerProvisionCommandBuilder::class)->build($server);

    expect($commands)->toBe([]);
});
test('build application stack includes nginx php and mysql packages', function () {
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
});
test('build application stack installs supervisor when provision flag enabled', function () {
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
});
test('build load balancer installs haproxy', function () {
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
});
test('build application stack supports apache openlitespeed and traefik', function () {
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
});
test('build emits named step markers for setup progress', function () {
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
});
test('build emits repeat safe install checks for reruns', function () {
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

    // The "already installed" guard now greps both upstreams
    // (sury.org primary, Launchpad fallback) and verifies an
    // InRelease file actually fetched — not just that the source
    // is configured.
    $this->assertStringContainsString('grep -RqsE', $joined);
    $this->assertStringContainsString('packages\\.sury\\.org/php|ppa\\.launchpadcontent\\.net/ondrej/php', $joined);
    $this->assertStringContainsString('[dply] ondrej/php repository already installed and indexed; skipping repository setup.', $joined);

    // Both keyring paths are emitted (case branch picks one at runtime).
    $this->assertStringContainsString('/etc/apt/keyrings/sury-php.gpg', $joined);
    $this->assertStringContainsString('/etc/apt/keyrings/ondrej-php.gpg', $joined);

    // Both upstream URLs appear in the source-list emit lines.
    $this->assertStringContainsString('https://packages.sury.org/php/', $joined);
    $this->assertStringContainsString('https://ppa.launchpadcontent.net/ondrej/php/ubuntu', $joined);
    $this->assertStringContainsString('timeout 300s apt-get update -y', $joined);
    $this->assertStringNotContainsString('rg -l "ondrej-ubuntu-php|ppa.launchpadcontent.net/ondrej/php"', $joined);
    $this->assertStringContainsString('command -v composer >/dev/null 2>&1', $joined);
    $this->assertStringContainsString('[dply] composer already installed; skipping installer.', $joined);
});
test('build application stack installs mise for non php runtimes', function () {
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
});
test('build application stack pins runtime defaults via mise use global', function () {
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
});
test('build application stack skips unknown runtime defaults', function () {
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
});
test('build application stack skips mise when disabled via config', function () {
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
});
test('build can force reinstall via config', function () {
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
});
test('build uses operational public key for deploy user bootstrap when present', function () {
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
});
