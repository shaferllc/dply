<?php

declare(strict_types=1);

namespace Tests\Feature\ShowServerCommandTest;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('renders full server profile in json', function () {
    $server = Server::factory()->create([
        'name' => 'prod-1',
        'ip_address' => '203.0.113.10',
        'meta' => [
            'php_version' => '8.4',
            'runtime_defaults' => ['node' => '22.1.0', 'python' => '3.12'],
        ],
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'jobs-app',
        'runtime' => 'node',
        'runtime_version' => '22.1.0',
    ]);
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:server:show', [
        'server' => $server->id,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['name'])->toBe('prod-1');
    expect($decoded['ip_address'])->toBe('203.0.113.10');
    expect($decoded['php_version'])->toBe('8.4');
    expect($decoded['runtime_defaults']['node'])->toBe('22.1.0');
    expect($decoded['database_engines'])->toHaveCount(1);
    expect($decoded['database_engines'][0]['engine'])->toBe('postgres');
    expect($decoded['site_count'])->toBe(1);
    expect($decoded['sites'][0]['name'])->toBe('jobs-app');
    expect($decoded['sites'][0]['primary_hostname'])->toBe('jobs.example.com');
});
test('resolves by name', function () {
    $server = Server::factory()->create(['name' => 'web-1']);

    Artisan::call('dply:server:show', [
        'server' => 'web-1',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['id'])->toBe($server->id);
});
test('resolves by ip', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.55']);

    Artisan::call('dply:server:show', [
        'server' => '203.0.113.55',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['id'])->toBe($server->id);
});
test('human output renders section headings', function () {
    $server = Server::factory()->create([
        'name' => 'lonely-1',
        'meta' => ['php_version' => '8.4'],
    ]);

    $exit = Artisan::call('dply:server:show', ['server' => $server->id]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Server', $output);
    $this->assertStringContainsString('Runtimes', $output);
    $this->assertStringContainsString('Database engines', $output);
    $this->assertStringContainsString('Sites', $output);
});
test('zero sites renders friendly message', function () {
    $server = Server::factory()->create();

    Artisan::call('dply:server:show', ['server' => $server->id]);
    $output = Artisan::output();

    $this->assertStringContainsString('No sites hosted yet', $output);
});
test('command fails when server not found', function () {
    $exit = Artisan::call('dply:server:show', ['server' => 'nope']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
