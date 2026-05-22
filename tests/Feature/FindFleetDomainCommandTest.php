<?php

declare(strict_types=1);

namespace Tests\Feature\FindFleetDomainCommandTest;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('finds exact match with site and server context', function () {
    $server = Server::factory()->create(['name' => 'web-1', 'ip_address' => '203.0.113.10']);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'jobs-app',
        'runtime' => 'node',
    ]);
    $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

    Artisan::call('dply:fleet:domain-find', [
        'hostname' => 'jobs.example.com',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
    $m = $decoded['matches'][0];
    expect($m['hostname'])->toBe('jobs.example.com');
    expect($m['is_primary'])->toBeTrue();
    expect($m['site_name'])->toBe('jobs-app');
    expect($m['site_runtime'])->toBe('node');
    expect($m['server_name'])->toBe('web-1');
    expect($m['server_ip'])->toBe('203.0.113.10');
});
test('normalizes input hostname', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create(['server_id' => $server->id]);
    $site->domains()->create(['hostname' => 'jobs.example.com']);

    Artisan::call('dply:fleet:domain-find', [
        'hostname' => 'HTTPS://Jobs.Example.com/',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(1);
});
test('contains mode finds substring matches', function () {
    $server = Server::factory()->create();
    $a = Site::factory()->create(['server_id' => $server->id, 'slug' => 'alpha']);
    $b = Site::factory()->create(['server_id' => $server->id, 'slug' => 'bravo']);
    $a->domains()->create(['hostname' => 'jobs.example.com']);
    $b->domains()->create(['hostname' => 'careers.example.com']);
    $b->domains()->create(['hostname' => 'unrelated.test.io']);

    Artisan::call('dply:fleet:domain-find', [
        'hostname' => 'example.com',
        '--contains' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['count'])->toBe(2);
    $hosts = array_column($decoded['matches'], 'hostname');
    expect($hosts)->toContain('jobs.example.com');
    expect($hosts)->toContain('careers.example.com');
});
test('exits non zero on no matches', function () {
    $server = Server::factory()->create();
    Site::factory()->create(['server_id' => $server->id]);

    $exit = Artisan::call('dply:fleet:domain-find', [
        'hostname' => 'missing.example.com',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1);
    expect($decoded['matches'])->toBe([]);
});
test('rejects empty hostname', function () {
    $exit = Artisan::call('dply:fleet:domain-find', ['hostname' => '']);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('cannot be empty', $output);
});
