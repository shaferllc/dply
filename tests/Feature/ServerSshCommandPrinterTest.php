<?php

declare(strict_types=1);

namespace Tests\Feature\ServerSshCommandPrinterTest;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('prints ssh command with default user', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
    ]);

    $exit = Artisan::call('dply:server:ssh', ['server' => $server->id]);
    $output = trim(Artisan::output());

    expect($exit)->toBe(0);
    $user = config('server_provision.deploy_ssh_user');
    expect($output)->toBe("ssh {$user}@203.0.113.10");
});
test('prints with root user when root flag', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
    ]);

    Artisan::call('dply:server:ssh', [
        'server' => $server->id,
        '--root' => true,
    ]);
    $output = trim(Artisan::output());

    expect($output)->toBe('ssh root@203.0.113.10');
});
test('includes port when non default', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 2222,
    ]);

    Artisan::call('dply:server:ssh', ['server' => $server->id]);
    $output = trim(Artisan::output());

    $this->assertStringContainsString('-p 2222', $output);
    $this->assertStringContainsString('@203.0.113.10', $output);
});
test('json output includes components', function () {
    $server = Server::factory()->create([
        'ip_address' => '203.0.113.10',
        'ssh_port' => 2222,
    ]);

    Artisan::call('dply:server:ssh', [
        'server' => $server->id,
        '--root' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['host'])->toBe('203.0.113.10');
    expect($decoded['port'])->toBe(2222);
    expect($decoded['user'])->toBe('root');
    $this->assertStringContainsString('-p 2222', $decoded['command']);
});
test('fails when server has no ip', function () {
    $server = Server::factory()->create(['ip_address' => null]);

    $exit = Artisan::call('dply:server:ssh', ['server' => $server->id]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('no IP address', $output);
});
test('fails when server not found', function () {
    $exit = Artisan::call('dply:server:ssh', ['server' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
