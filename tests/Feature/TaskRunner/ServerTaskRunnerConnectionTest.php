<?php

namespace Tests\Feature\TaskRunner\ServerTaskRunnerConnectionTest;

use App\Models\Server;
use App\Modules\TaskRunner\Connection;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function validPrivateKey(): string
{
    $path = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');

    return file_get_contents($path);
}

test('connection as user uses server ssh fields', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 2222,
        'ssh_user' => 'deploy',
        'ssh_private_key' => validPrivateKey(),
    ]);

    $connection = $server->connectionAsUser();

    expect($connection)->toBeInstanceOf(Connection::class);
    expect($connection->host)->toBe('203.0.113.10');
    expect($connection->port)->toBe(2222);
    expect($connection->username)->toBe('deploy');
    expect($connection->scriptPath)->toBe('/home/deploy/.dply-task-runner');
});

test('connection as root uses root script path', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.20',
        'ssh_port' => 22,
        'ssh_user' => 'deploy',
        'ssh_private_key' => validPrivateKey(),
    ]);

    $connection = $server->connectionAsRoot();

    expect($connection->username)->toBe('root');
    expect($connection->scriptPath)->toBe('/root/.dply-task-runner');
});

test('connection as user requires ssh user', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.1',
        'ssh_user' => '   ',
        'ssh_private_key' => validPrivateKey(),
    ]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('no SSH user');

    $server->connectionAsUser();
});

test('connection as operational user prefers operational private key', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.30',
        'ssh_port' => 2222,
        'ssh_user' => 'deploy',
        'ssh_private_key' => 'legacy-key',
        'ssh_operational_private_key' => validPrivateKey(),
    ]);

    $connection = $server->connectionAsOperationalUser();

    expect($connection)->toBeInstanceOf(Connection::class);
    expect($connection->username)->toBe('deploy');
    expect($connection->privateKey)->toBe(validPrivateKey());
    expect($connection->scriptPath)->toBe('/home/deploy/.dply-task-runner');
});

test('connection as recovery root prefers recovery private key', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.40',
        'ssh_port' => 22,
        'ssh_user' => 'deploy',
        'ssh_private_key' => 'legacy-key',
        'ssh_recovery_private_key' => validPrivateKey(),
    ]);

    $connection = $server->connectionAsRecoveryRoot();

    expect($connection->username)->toBe('root');
    expect($connection->privateKey)->toBe(validPrivateKey());
    expect($connection->scriptPath)->toBe('/root/.dply-task-runner');
});

test('fake cloud server ssh user dply uses default home script path', function () {
    // env_flag is now part of the isFakeServer() contract — without
    // it set, the gate refuses to treat any server as fake even if
    // the provider_id matches the sentinel. That guard exists to
    // stop stale workers routing through localhost after the operator
    // disables fake-cloud; tests need to opt in explicitly.
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.provider_id_sentinel' => 'fake-local-test',
    ]);

    $server = Server::factory()->create([
        'ip_address' => '127.0.0.1',
        'ssh_port' => 2222,
        'ssh_user' => 'dply',
        'ssh_private_key' => validPrivateKey(),
        'provider_id' => 'fake-local-test',
    ]);

    expect(FakeCloudProvision::isFakeServer($server))->toBeTrue();

    $connection = $server->connectionAsUser();

    expect($connection->scriptPath)->toBe('/home/dply/.dply-task-runner');
});

test('connection helpers fall back to legacy private key during rollout', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.50',
        'ssh_port' => 22,
        'ssh_user' => 'deploy',
        'ssh_private_key' => validPrivateKey(),
        'ssh_operational_private_key' => null,
        'ssh_recovery_private_key' => null,
    ]);

    $operational = $server->connectionAsOperationalUser();
    $recovery = $server->connectionAsRecoveryRoot();

    expect($operational->privateKey)->toBe(validPrivateKey());
    expect($recovery->privateKey)->toBe(validPrivateKey());
});
