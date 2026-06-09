<?php

declare(strict_types=1);

namespace Tests\Feature\SetServerMetaCommandTest;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('sets a top level key', function () {
    $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

    // Pass --raw because "8.4" would otherwise auto-parse as a
    // float, and version strings are conventionally stored as
    // strings in meta.
    Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'php_version=8.4',
        '--raw' => true,
    ]);

    $server->refresh();
    expect($server->meta['php_version'])->toBe('8.4');
    expect($server->meta['webserver'])->toBe('nginx');
});
test('sets a nested key with dot notation', function () {
    $server = Server::factory()->create(['meta' => []]);

    Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'runtime_defaults.node=22.1.0',
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['key'])->toBe('runtime_defaults.node');
    $server->refresh();
    expect($server->meta['runtime_defaults']['node'])->toBe('22.1.0');
});
test('auto parses json literals', function () {
    $server = Server::factory()->create(['meta' => []]);

    Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'is_production=true',
    ]);
    $server->refresh();
    expect($server->meta['is_production'])->toBeTrue();

    Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'cpu_count=8',
    ]);
    $server->refresh();
    expect($server->meta['cpu_count'])->toBe(8);
});
test('raw flag disables auto parse', function () {
    $server = Server::factory()->create(['meta' => []]);

    Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'flag=true',
        '--raw' => true,
    ]);

    $server->refresh();
    expect($server->meta['flag'])->toBe('true');
});
test('unset removes a key', function () {
    $server = Server::factory()->create(['meta' => ['webserver' => 'nginx', 'php_version' => '8.4']]);

    Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'php_version=',
        '--unset' => true,
    ]);

    $server->refresh();
    $this->assertArrayNotHasKey('php_version', $server->meta);
    expect($server->meta['webserver'])->toBe('nginx');
});
test('dry run does not persist', function () {
    $server = Server::factory()->create(['meta' => ['webserver' => 'nginx']]);

    Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'webserver=apache',
        '--dry-run' => true,
        '--json' => true,
    ]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded['dry_run'])->toBeTrue();
    expect($server->fresh()->meta['webserver'])->toBe('nginx');
});
test('rejects invalid assignment format', function () {
    $server = Server::factory()->create();

    $exit = Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => 'no-equal',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('key=value', $output);
});
test('rejects invalid key', function () {
    $server = Server::factory()->create();

    $exit = Artisan::call('dply:server:meta-set', [
        'server' => $server->id,
        'assignment' => '@bad@key=foo',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Key must match', $output);
});
test('fails when server not found', function () {
    $exit = Artisan::call('dply:server:meta-set', [
        'server' => 'nope',
        'assignment' => 'foo=bar',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
