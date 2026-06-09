<?php

declare(strict_types=1);

namespace Tests\Feature\InstallRuntimeCommandTest;

use App\Actions\Servers\InstallRuntimeOnServer;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;

uses(RefreshDatabase::class);

test('command installs node via action', function () {
    $server = Server::factory()->ready()->create([
        'name' => 'edge-1',
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $action = Mockery::mock(InstallRuntimeOnServer::class);
    $action->shouldReceive('execute')
        ->once()
        ->withArgs(function (Server $s, string $r, string $v) use ($server) {
            return $s->id === $server->id && $r === 'node' && $v === '22.7.0';
        })
        ->andReturn([
            'installed' => true,
            'runtime' => 'node',
            'version' => '22.7.0',
            'output' => 'mise install line ran',
        ]);
    $this->app->instance(InstallRuntimeOnServer::class, $action);

    $exit = Artisan::call('dply:install-runtime', [
        'server' => 'edge-1',
        'runtime' => 'node',
        'version' => '22.7.0',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Installed node@22.7.0 on edge-1', $output);
});
test('command resolves server by id', function () {
    $server = Server::factory()->ready()->create([
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $action = Mockery::mock(InstallRuntimeOnServer::class);
    $action->shouldReceive('execute')->once()->andReturn([
        'installed' => true,
        'runtime' => 'python',
        'version' => '3.12',
        'output' => '',
    ]);
    $this->app->instance(InstallRuntimeOnServer::class, $action);

    $exit = Artisan::call('dply:install-runtime', [
        'server' => $server->id,
        'runtime' => 'python',
        'version' => '3.12',
    ]);

    expect($exit)->toBe(0);
});
test('command fails when server not found', function () {
    $exit = Artisan::call('dply:install-runtime', [
        'server' => 'no-such-server',
        'runtime' => 'node',
        'version' => '22',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
test('command warns when action silently skips', function () {
    $server = Server::factory()->ready()->create([
        'name' => 'edge-1',
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $action = Mockery::mock(InstallRuntimeOnServer::class);
    $action->shouldReceive('execute')->once()->andReturn([
        'installed' => false,
        'runtime' => 'php',
        'version' => '8.4',
        'output' => '',
    ]);
    $this->app->instance(InstallRuntimeOnServer::class, $action);

    $exit = Artisan::call('dply:install-runtime', [
        'server' => 'edge-1',
        'runtime' => 'php',
        'version' => '8.4',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Skipped', $output);
});
test('command emits machine readable json with flag', function () {
    $server = Server::factory()->ready()->create([
        'name' => 'edge-1',
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $action = Mockery::mock(InstallRuntimeOnServer::class);
    $action->shouldReceive('execute')->once()->andReturn([
        'installed' => true,
        'runtime' => 'ruby',
        'version' => '3.3.4',
        'output' => 'mise: installing ruby@3.3.4',
    ]);
    $this->app->instance(InstallRuntimeOnServer::class, $action);

    $exit = Artisan::call('dply:install-runtime', [
        'server' => 'edge-1',
        'runtime' => 'ruby',
        'version' => '3.3.4',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['runtime'])->toBe('ruby');
    expect($decoded['version'])->toBe('3.3.4');
    expect($decoded['server_id'])->toBe($server->id);
});
test('command emits json error when action throws', function () {
    $server = Server::factory()->ready()->create([
        'name' => 'edge-1',
        'ssh_user' => 'dply',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----\n",
    ]);

    $action = Mockery::mock(InstallRuntimeOnServer::class);
    $action->shouldReceive('execute')->once()->andThrow(new \RuntimeException('SSH closed'));
    $this->app->instance(InstallRuntimeOnServer::class, $action);

    $exit = Artisan::call('dply:install-runtime', [
        'server' => 'edge-1',
        'runtime' => 'node',
        'version' => '22',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $decoded = json_decode($output, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['error'])->toBe('SSH closed');
});
