<?php

declare(strict_types=1);

namespace Tests\Feature\ServerDoctorCommandTest;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('command reports clean state for polyglot server', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => [
            'php_version' => '8.4',
            'runtime_defaults' => ['node' => '22', 'python' => '3.12'],
        ],
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);

    $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('node', $output);
    $this->assertStringContainsString('22', $output);
    $this->assertStringContainsString('python', $output);
    $this->assertStringContainsString('3.12', $output);
    $this->assertStringContainsString('postgres', $output);
    $this->assertStringContainsString('No drift detected', $output);
});
test('command flags sites with unregistered engine', function () {
    $server = Server::factory()->create(['name' => 'edge-1']);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'orphan',
        'database_engine' => 'mariadb',
    ]);

    $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('orphan', $output);
    $this->assertStringContainsString('mariadb', $output);
    $this->assertStringContainsString('NOT registered', $output);
});
test('command flags sites with non pinned runtime', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => ['runtime_defaults' => ['node' => '22']],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'pyapp',
        'runtime' => 'python',
        'runtime_version' => '3.12',
    ]);

    $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('pyapp', $output);
    $this->assertStringContainsString('python', $output);
    $this->assertStringContainsString('mise installs on demand', $output);
});
test('command does not flag php or static sites', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => ['php_version' => '8.4'],
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'laravel-app',
        'runtime' => 'php',
        'runtime_version' => '8.4',
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'static-site',
        'runtime' => 'static',
    ]);

    $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No drift detected', $output);
});
test('command emits json', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => ['runtime_defaults' => ['node' => '22']],
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);

    $exit = Artisan::call('dply:server:doctor', [
        'server' => 'edge-1',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['server_id'])->toBe($server->id);
    expect($decoded['runtime_defaults'])->toBe(['node' => '22']);
    expect($decoded['engines'][0]['engine'])->toBe('postgres');
});
test('command fails when server not found', function () {
    $exit = Artisan::call('dply:server:doctor', ['server' => 'no-such']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
