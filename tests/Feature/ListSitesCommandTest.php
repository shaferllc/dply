<?php

declare(strict_types=1);

namespace Tests\Feature\ListSitesCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('command lists all sites with runtime info', function () {
    $server = Server::factory()->create(['name' => 'edge-1']);
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'jobs',
        'runtime' => 'node',
        'runtime_version' => '22',
        'internal_port' => 30001,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'shop',
        'runtime' => 'php',
        'runtime_version' => '8.4',
    ]);

    $exit = Artisan::call('dply:site:list');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('jobs', $output);
    $this->assertStringContainsString('node 22', $output);
    $this->assertStringContainsString('shop', $output);
    $this->assertStringContainsString('php 8.4', $output);
    $this->assertStringContainsString('30001', $output);
});
test('command filters by runtime', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'name' => 'jobs', 'runtime' => 'node']);
    Site::factory()->create(['server_id' => $server->id, 'name' => 'shop', 'runtime' => 'php']);

    $exit = Artisan::call('dply:site:list', ['--runtime' => 'node', '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['count'])->toBe(1);
    expect($decoded['sites'][0]['name'])->toBe('jobs');
});
test('command filters by server', function () {
    $a = Server::factory()->create(['name' => 'edge-a']);
    $b = Server::factory()->create(['name' => 'edge-b']);
    Site::factory()->create(['server_id' => $a->id, 'name' => 'on-a']);
    Site::factory()->create(['server_id' => $b->id, 'name' => 'on-b']);

    $exit = Artisan::call('dply:site:list', ['--server' => 'edge-a', '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['count'])->toBe(1);
    expect($decoded['sites'][0]['name'])->toBe('on-a');
});
test('command fails for unknown server', function () {
    $exit = Artisan::call('dply:site:list', ['--server' => 'no-such']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
test('command reports no match when filter empty', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

    $exit = Artisan::call('dply:site:list', ['--runtime' => 'node']);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No sites match', $output);
});
test('limit clamps results', function () {
    $server = Server::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        Site::factory()->create(['server_id' => $server->id, 'name' => "s{$i}"]);
    }

    $exit = Artisan::call('dply:site:list', ['--limit' => 2, '--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded['count'])->toBe(2);
});
