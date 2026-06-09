<?php

namespace Tests\Unit\Services\ConsoleArgspecsTest;

use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Support\Console\ConsoleArgspecs;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create a Server and seed a stack_summary artifact so ServerInstalledServices
 * resolves real tags (instead of fail-open 'unknown').
 */
function serverWithStack(array $summary): Server
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

test('argspecs returns array', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => [],
        ],
    ]);

    $argspecs = ConsoleArgspecs::for($server);

    expect($argspecs)->toBeArray();
});

test('systemctl argspec has positional arguments', function () {
    $server = Server::factory()->create(['meta' => []]);

    $argspecs = ConsoleArgspecs::for($server);

    expect($argspecs)->toHaveKey('systemctl');
    expect($argspecs['systemctl'])->toHaveKey('positional');

    $positional = $argspecs['systemctl']['positional'];
    expect($positional)->toHaveKey(1);
    // verbs
    expect($positional)->toHaveKey(2);

    // units
    // Should have common verbs
    expect($positional[1])->toContain('start');
    expect($positional[1])->toContain('stop');
    expect($positional[1])->toContain('restart');
    expect($positional[1])->toContain('reload');
    expect($positional[1])->toContain('status');
    expect($positional[1])->toContain('enable');
    expect($positional[1])->toContain('disable');
});

test('systemctl units include nginx when installed', function () {
    $server = serverWithStack(['expected_services' => ['nginx']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('nginx');
});

test('systemctl units include apache when installed', function () {
    $server = serverWithStack(['expected_services' => ['apache']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('apache2');
});

test('systemctl units include caddy when installed', function () {
    $server = serverWithStack(['expected_services' => ['caddy']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('caddy');
});

test('systemctl units include php fpm when php installed', function () {
    $server = serverWithStack([
        'expected_services' => ['php'],
        'php_version' => '8.3',
    ]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('php8.3-fpm');
});

test('systemctl units fallback php fpm when version unknown', function () {
    // php_version intentionally missing
    $server = serverWithStack(['expected_services' => ['php']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('php-fpm');
});

test('systemctl units include mysql when installed', function () {
    $server = serverWithStack(['expected_services' => ['mysql']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('mysql');
    expect($units)->toContain('mariadb');
});

test('systemctl units include postgres when installed', function () {
    $server = serverWithStack(['expected_services' => ['postgres']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('postgresql');
});

test('systemctl units include redis when installed', function () {
    $server = serverWithStack(['expected_services' => ['redis']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('redis-server');
});

test('systemctl units include valkey when installed', function () {
    $server = serverWithStack(['expected_services' => ['valkey']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('valkey-server');
});

test('systemctl units include memcached when installed', function () {
    $server = serverWithStack(['expected_services' => ['memcached']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('memcached');
});

test('systemctl units include supervisor when installed', function () {
    $server = serverWithStack(['expected_services' => ['supervisor']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('supervisor');
});

test('systemctl units include docker when installed', function () {
    $server = serverWithStack(['expected_services' => ['docker']]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('docker');
});

test('systemctl units always include system units', function () {
    $server = Server::factory()->create(['meta' => []]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    // These should always be present regardless of services
    expect($units)->toContain('cron');
    expect($units)->toContain('ssh');
    expect($units)->toContain('ufw');
});

test('systemctl units are unique', function () {
    // Test that units don't duplicate when multiple conditions match
    $server = serverWithStack([
        'expected_services' => ['nginx', 'php', 'mysql'],
        'php_version' => '8.3',
    ]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect(array_unique($units))->toEqual($units);
});

test('service argspec exists', function () {
    $server = Server::factory()->create(['meta' => []]);

    $argspecs = ConsoleArgspecs::for($server);

    expect($argspecs)->toHaveKey('service');
    expect($argspecs['service'])->toHaveKey('positional');
});

test('journalctl argspec has after flag', function () {
    $server = serverWithStack([
        'expected_services' => ['nginx', 'php'],
        'php_version' => '8.3',
    ]);

    $argspecs = ConsoleArgspecs::for($server);

    expect($argspecs)->toHaveKey('journalctl');
    expect($argspecs['journalctl'])->toHaveKey('after_flag');

    $afterFlag = $argspecs['journalctl']['after_flag'];
    expect($afterFlag)->toHaveKey('-u');
    expect($afterFlag)->toHaveKey('--unit');

    // Both should suggest same units
    expect($afterFlag['--unit'])->toEqual($afterFlag['-u']);
    expect($afterFlag['-u'])->toContain('nginx');
    expect($afterFlag['-u'])->toContain('php8.3-fpm');
});

test('tail argspec has log paths', function () {
    $server = serverWithStack(['expected_services' => ['nginx']]);

    $argspecs = ConsoleArgspecs::for($server);

    expect($argspecs)->toHaveKey('tail');
    expect($argspecs['tail'])->toHaveKey('positional');
    expect($argspecs['tail']['positional'])->toHaveKey(1);

    $paths = $argspecs['tail']['positional'][1];
    expect($paths)->toContain('/var/log/syslog');
    expect($paths)->toContain('/var/log/auth.log');
    expect($paths)->toContain('/var/log/nginx/error.log');
    expect($paths)->toContain('/var/log/nginx/access.log');
});

test('less argspec matches tail', function () {
    $server = serverWithStack([
        'expected_services' => ['nginx', 'php'],
        'php_version' => '8.3',
    ]);

    $argspecs = ConsoleArgspecs::for($server);

    expect($argspecs)->toHaveKey('less');
    expect($argspecs['less']['positional'][1])->toEqual($argspecs['tail']['positional'][1]);
});

test('php log paths use versioned path', function () {
    $server = serverWithStack([
        'expected_services' => ['php'],
        'php_version' => '8.2',
    ]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect($paths)->toContain('/var/log/php8.2-fpm.log');
});

test('php log paths fallback when version unknown', function () {
    $server = serverWithStack(['expected_services' => ['php']]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect($paths)->toContain('/var/log/php-fpm.log');
});

test('caddy log paths included when caddy installed', function () {
    $server = serverWithStack(['expected_services' => ['caddy']]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect($paths)->toContain('/var/log/caddy/access.log');
});

test('mysql log paths included when mysql installed', function () {
    $server = serverWithStack(['expected_services' => ['mysql']]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect($paths)->toContain('/var/log/mysql/error.log');
});

test('postgres log paths included when postgres installed', function () {
    $server = serverWithStack(['expected_services' => ['postgres']]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect($paths)->toContain('/var/log/postgresql/');
});

test('redis log paths included when redis installed', function () {
    $server = serverWithStack(['expected_services' => ['redis']]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect($paths)->toContain('/var/log/redis/redis-server.log');
});

test('ufw log paths included when ufw installed', function () {
    $server = Server::factory()->create([
        'meta' => [
            'expected_services' => ['ufw'],
        ],
    ]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect($paths)->toContain('/var/log/ufw.log');
});

test('empty server has basic argspecs', function () {
    $server = Server::factory()->create(['meta' => []]);

    $argspecs = ConsoleArgspecs::for($server);

    // Should always have systemctl and service
    expect($argspecs)->toHaveKey('systemctl');
    expect($argspecs)->toHaveKey('service');
    expect($argspecs)->toHaveKey('journalctl');
    expect($argspecs)->toHaveKey('tail');
    expect($argspecs)->toHaveKey('less');
});

test('log paths are unique', function () {
    $server = serverWithStack([
        'expected_services' => ['nginx', 'php', 'mysql', 'redis', 'ufw'],
        'php_version' => '8.3',
    ]);

    $argspecs = ConsoleArgspecs::for($server);
    $paths = $argspecs['tail']['positional'][1];

    expect(array_unique($paths))->toEqual($paths);
});

test('combined server has all relevant units', function () {
    $server = serverWithStack([
        'expected_services' => ['nginx', 'php', 'mysql', 'redis', 'supervisor', 'docker'],
        'php_version' => '8.3',
    ]);

    $argspecs = ConsoleArgspecs::for($server);
    $units = $argspecs['systemctl']['positional'][2];

    expect($units)->toContain('nginx');
    expect($units)->toContain('php8.3-fpm');
    expect($units)->toContain('mysql');
    expect($units)->toContain('redis-server');
    expect($units)->toContain('supervisor');
    expect($units)->toContain('docker');
    expect($units)->toContain('cron');
    expect($units)->toContain('ssh');
    expect($units)->toContain('ufw');
});
