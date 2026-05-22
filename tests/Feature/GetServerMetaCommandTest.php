<?php

declare(strict_types=1);

namespace Tests\Feature\GetServerMetaCommandTest;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('prints string value plain', function () {
    $server = Server::factory()->create([
        'meta' => ['webserver' => 'nginx'],
    ]);

    $exit = Artisan::call('dply:server:meta-get', [
        'server' => $server->id,
        'key' => 'webserver',
    ]);
    $output = trim(Artisan::output());

    expect($exit)->toBe(0);
    expect($output)->toBe('nginx');
});
test('prints nested value via dot notation', function () {
    $server = Server::factory()->create([
        'meta' => ['runtime_defaults' => ['node' => '22.1.0']],
    ]);

    Artisan::call('dply:server:meta-get', [
        'server' => $server->id,
        'key' => 'runtime_defaults.node',
    ]);
    $output = trim(Artisan::output());

    expect($output)->toBe('22.1.0');
});
test('prints json for array values', function () {
    $server = Server::factory()->create([
        'meta' => ['runtime_defaults' => ['node' => '22', 'python' => '3.12']],
    ]);

    Artisan::call('dply:server:meta-get', [
        'server' => $server->id,
        'key' => 'runtime_defaults',
    ]);
    $output = trim(Artisan::output());
    $decoded = json_decode($output, true);

    expect($decoded)->toBe(['node' => '22', 'python' => '3.12']);
});
test('dumps full meta when no key given', function () {
    $server = Server::factory()->create([
        'meta' => ['webserver' => 'nginx', 'php_version' => '8.4'],
    ]);

    Artisan::call('dply:server:meta-get', ['server' => $server->id]);
    $decoded = json_decode(trim(Artisan::output()), true);

    expect($decoded['webserver'])->toBe('nginx');
    expect($decoded['php_version'])->toBe('8.4');
});
test('json output wraps payload', function () {
    $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

    Artisan::call('dply:server:meta-get', [
        'server' => $server->id,
        'key' => 'webserver',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['key'])->toBe('webserver');
    expect($decoded['value'])->toBe('nginx');
    expect($decoded['present'])->toBeTrue();
});
test('exits non zero when key missing', function () {
    $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

    $exit = Artisan::call('dply:server:meta-get', [
        'server' => $server->id,
        'key' => 'nonexistent',
    ]);

    expect($exit)->toBe(1);
});
test('json with missing key includes present false', function () {
    $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

    Artisan::call('dply:server:meta-get', [
        'server' => $server->id,
        'key' => 'nonexistent',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['present'])->toBeFalse();
    expect($decoded['value'])->toBeNull();
});
test('command fails when server not found', function () {
    $exit = Artisan::call('dply:server:meta-get', [
        'server' => 'nope',
        'key' => 'foo',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
