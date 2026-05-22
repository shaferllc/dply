<?php

declare(strict_types=1);

namespace Tests\Feature\ListServersCommandTest;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('command lists servers with runtime engines and site count', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => [
            'php_version' => '8.4',
            'runtime_defaults' => ['node' => '22'],
        ],
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    Site::factory()->count(3)->create(['server_id' => $server->id]);

    $exit = Artisan::call('dply:server:list');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('edge-1', $output);
    $this->assertStringContainsString('php 8.4', $output);
    $this->assertStringContainsString('node', $output);
    $this->assertStringContainsString('postgres*', $output);
    $this->assertStringContainsString('3', $output);
    // site count
});
test('ready flag filters to ready servers', function () {
    Server::factory()->create(['name' => 'edge-ready', 'status' => Server::STATUS_READY]);
    Server::factory()->create(['name' => 'edge-pending', 'status' => Server::STATUS_PENDING]);

    $exit = Artisan::call('dply:server:list', ['--ready' => true, '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    $names = array_column($decoded['servers'], 'name');
    expect($names)->toContain('edge-ready');
    expect($names)->not->toContain('edge-pending');
});
test('command emits json with full data', function () {
    $server = Server::factory()->create([
        'name' => 'edge-1',
        'meta' => ['runtime_defaults' => ['python' => '3.12']],
    ]);
    Site::factory()->create(['server_id' => $server->id]);

    $exit = Artisan::call('dply:server:list', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['count'])->toBeGreaterThan(0);
    $matched = collect($decoded['servers'])->firstWhere('name', 'edge-1');
    expect($matched)->not->toBeNull();
    expect($matched['runtimes'])->toBe(['python']);
    expect($matched['site_count'])->toBe(1);
});
test('command handles empty state', function () {
    $exit = Artisan::call('dply:server:list');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No servers found', $output);
});
