<?php

declare(strict_types=1);

namespace Tests\Feature\ListFleetDomainsCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('lists all domains in fleet', function () {
    $server = Server::factory()->create(['name' => 'web-1']);
    $a = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php', 'slug' => 'alpha']);
    $b = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node', 'slug' => 'bravo']);
    $a->domains()->create(['hostname' => 'a.example.com', 'is_primary' => true]);
    $b->domains()->create(['hostname' => 'b.example.com', 'is_primary' => true]);
    $b->domains()->create(['hostname' => 'b-alias.example.com', 'is_primary' => false]);

    Artisan::call('dply:fleet:domain-list', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(3);
    $hosts = array_column($decoded['domains'], 'hostname');
    expect($hosts)->toBe(['a.example.com', 'b-alias.example.com', 'b.example.com']);
});
test('primary only filter', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true]);
    $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

    Artisan::call('dply:fleet:domain-list', [
        '--primary-only' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['domains'][0]['hostname'])->toBe('primary.example.com');
});
test('runtime filter', function () {
    $server = Server::factory()->create();
    $php = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php', 'slug' => 'php-app']);
    $node = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node', 'slug' => 'node-app']);
    $php->domains()->create(['hostname' => 'php.example.com']);
    $node->domains()->create(['hostname' => 'node.example.com']);

    Artisan::call('dply:fleet:domain-list', [
        '--runtime' => 'node',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    expect($decoded['domains'][0]['hostname'])->toBe('node.example.com');
});
test('includes server context', function () {
    $server = Server::factory()->create(['name' => 'prod-1', 'ip_address' => '203.0.113.5']);
    $site = Site::factory()->create(['server_id' => $server->id, 'name' => 'jobs-app']);
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:fleet:domain-list', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    $row = $decoded['domains'][0];
    expect($row['site_name'])->toBe('jobs-app');
    expect($row['server_name'])->toBe('prod-1');
    expect($row['server_ip'])->toBe('203.0.113.5');
});
test('empty fleet returns zero count', function () {
    Artisan::call('dply:fleet:domain-list', ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(0);
});
